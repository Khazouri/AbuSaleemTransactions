<?php

namespace App\Services;

use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\RequestSuspension;
use App\Models\User;
use App\Models\WorkflowStage;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Stage 78 — [D] Art. 105's قاعدة منع القرار المبني على معلومات ناقصة.
 *
 * "إذا ظهر **قبل الاعتماد أو التنفيذ** أن معلومة جوهرية غير صحيحة أو أن
 * مستندًا أساسيًا محل شك: **يوقف التنفيذ فورًا من الناحية الإجرائية ويحال
 * الموضوع للمراجعة القانونية والجهة المختصة قبل ترتيب أثر جديد عليه**."
 *
 * Nothing in this system could stop a file mid-approval before this stage.
 * Art. 104 is why it has to be able to: that article lists قرار صدر... من جهة
 * غير مختصة، أو استند إلى معلومات وبيانات غير صحيحة among the grounds on which
 * a decision is void outright, so continuing to approve a file whose facts are
 * in doubt manufactures a void decision rather than a delayed one.
 *
 * Three things make the article real rather than narrated:
 *
 * 1. **يوقف فورًا** — `suspend()` is status-only. The stage is untouched and
 *    no `RequestStageLog` row is written, because nothing moved; this is a
 *    hold, not a transition. Stage 77's own precedent for the same shape.
 * 2. **ويحال الموضوع للمراجعة القانونية** — a suspended request enters
 *    `RequestLegalReviewController`'s queue, and `lift()` refuses until a
 *    `RequestLegalReview` has actually been recorded *after* the suspension
 *    began. A review recorded months earlier says nothing about the doubt
 *    raised today.
 * 3. **قبل ترتيب أثر جديد عليه** — while a suspension is open, `approve` is
 *    refused at every entry point that could arrange a new effect. Closure and
 *    execution need no guard of their own and that is deliberate rather than
 *    an oversight: `RequestClosureService::CLOSABLE_STATUSES` and
 *    `RequestExecutionService::EXECUTABLE_STATUS` are status whitelists that
 *    `execution_suspended` is simply not on, so Appendix 48 needs no ninth
 *    condition.
 */
class RequestSuspensionService
{
    public const SUSPENDED_STATUS = 'execution_suspended';

    /**
     * Art. 105's own window, "قبل الاعتماد أو التنفيذ", as the statuses that
     * span it: the approval cycle Stage 77 already named, plus the two states
     * on either side of the execution referral.
     *
     * `executed` is deliberately absent — by then the effect has been
     * arranged, and Art. 105 is about preventing one, not undoing one. Undoing
     * a completed execution is Stage 66's reopen mechanism.
     *
     * @var list<string>
     */
    public const SUSPENDABLE_STATUSES = [
        ...ApprovalReturnService::APPROVAL_CYCLE_STATUSES,
        'final_approved',
        RequestExecutionService::EXECUTABLE_STATUS,
    ];

    /** Art. 78's إعادة العرض, the same branch Stage 77's substantive return takes. */
    public const COMMITTEE_TARGET_STAGE = 'receive_from_committee';

    public const COMMITTEE_TARGET_STATUS = 'reopened_for_representation';

    public const BLOCK_MESSAGE = 'المعاملة موقوفة إجرائياً وفق المادة 105 حتى تُستكمل المراجعة القانونية.';

    public function __construct(private readonly WorkflowService $workflow) {}

    /** Null when a suspension may be recorded, else the Arabic reason it may not. */
    public function refusalReason(Request $requestRecord): ?string
    {
        if ($this->openSuspension($requestRecord) !== null) {
            return 'توجد بالفعل حالة إيقاف إجرائي مفتوحة على هذه المعاملة.';
        }

        if (! in_array($requestRecord->status?->code, self::SUSPENDABLE_STATUSES, true)) {
            return 'لا يوقف التنفيذ إلا لمعاملة في دورة الاعتماد أو التنفيذ.';
        }

        return null;
    }

    public function openSuspension(Request $requestRecord): ?RequestSuspension
    {
        return $requestRecord->openSuspension()->first();
    }

    /**
     * Why a lift is refused, if it is.
     *
     * The legal-review requirement is Art. 105's "ويحال الموضوع للمراجعة
     * القانونية" enforced rather than assumed — without it a suspension could
     * be raised and dropped again by the same hand with nothing examined in
     * between, which is precisely the شكلية Art. 104 rules out.
     */
    public function liftRefusalReason(Request $requestRecord, ?RequestSuspension $suspension): ?string
    {
        if ($suspension === null) {
            return 'لا توجد حالة إيقاف إجرائي مفتوحة على هذه المعاملة.';
        }

        $reviewed = $requestRecord->legalReviews()
            ->where('created_at', '>=', $suspension->suspended_at)
            ->exists();

        if (! $reviewed) {
            return 'لا يرفع الإيقاف قبل إثبات مراجعة قانونية بعد تاريخ الإيقاف.';
        }

        return null;
    }

    /**
     * Status-only, and the frozen status is stored so `lift()` can put the
     * file back exactly where Art. 105 interrupted it rather than guessing.
     *
     * @param  array{ground: string, detail: string}  $card
     */
    public function suspend(Request $requestRecord, User $actor, array $card): RequestSuspension
    {
        return DB::transaction(function () use ($requestRecord, $actor, $card) {
            $locked = Request::query()
                ->with('status:id,code')
                ->lockForUpdate()
                ->findOrFail($requestRecord->id);

            // Re-checked inside the lock: the controller's own check ran
            // before it, and the file could have moved on since.
            if (($reason = $this->refusalReason($locked)) !== null) {
                throw new DomainException($reason);
            }

            $suspended = RequestStatus::query()->where('code', self::SUSPENDED_STATUS)->firstOrFail();
            $fromStatusId = $locked->status_id;

            $record = RequestSuspension::create([
                'request_id' => $locked->id,
                'suspended_from_status_id' => $fromStatusId,
                'ground' => $card['ground'],
                'detail' => $card['detail'],
                'suspended_by_user_id' => $actor->id,
                'suspended_at' => now(),
            ]);

            $locked->update(['status_id' => $suspended->id]);

            RequestStatusHistory::create([
                'request_id' => $locked->id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $suspended->id,
                'reason' => 'إيقاف إجرائي وفق المادة 105 ('
                    .RequestSuspension::groundLabel($card['ground']).'): '.$card['detail'],
                'changed_by_user_id' => $actor->id,
                'changed_at' => now(),
            ]);

            return $record;
        });
    }

    /**
     * Record what the legal review concluded and route the file.
     *
     * `fact_confirmed` restores the frozen status where it stood — the doubt
     * was examined and cleared, so nothing about the file's position changed
     * and re-deriving it would be inventing a move the article does not
     * describe. `referred_to_committee` goes through
     * `WorkflowService::reopenAtStage()`, which keeps that service the sole
     * owner of stage movement and leaves the same audit trail an ordinary
     * transition would — Stage 77's substantive branch verbatim.
     */
    public function lift(Request $requestRecord, RequestSuspension $suspension, User $actor, string $action, ?string $note): Request
    {
        $reasonText = 'رفع الإيقاف الإجرائي ('
            .RequestSuspension::groundLabel($suspension->ground).'): '
            .(RequestSuspension::RESOLUTIONS[$action] ?? $action)
            .($note !== null && $note !== '' ? ' — '.$note : '');

        if ($action === 'referred_to_committee') {
            $targetStage = WorkflowStage::query()
                ->where('code', self::COMMITTEE_TARGET_STAGE)
                ->firstOrFail();

            $moved = $this->workflow->reopenAtStage(
                $requestRecord,
                $targetStage,
                $actor,
                $reasonText,
                action: 'article_105_restudy',
                statusCode: self::COMMITTEE_TARGET_STATUS,
            );

            $suspension->update([
                'resolution_action' => $action,
                'resolution_note' => $note,
                'resolved_by_user_id' => $actor->id,
                'resolved_at' => now(),
            ]);

            return $moved;
        }

        return DB::transaction(function () use ($requestRecord, $suspension, $actor, $action, $note, $reasonText) {
            $locked = Request::query()->lockForUpdate()->findOrFail($requestRecord->id);

            $restoreId = $suspension->suspended_from_status_id;

            if ($restoreId === null) {
                throw new DomainException('تعذر تحديد حالة المعاملة قبل الإيقاف.');
            }

            $fromStatusId = $locked->status_id;
            $locked->update(['status_id' => $restoreId]);

            RequestStatusHistory::create([
                'request_id' => $locked->id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $restoreId,
                'reason' => $reasonText,
                'changed_by_user_id' => $actor->id,
                'changed_at' => now(),
            ]);

            $suspension->update([
                'resolution_action' => $action,
                'resolution_note' => $note,
                'resolved_by_user_id' => $actor->id,
                'resolved_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
