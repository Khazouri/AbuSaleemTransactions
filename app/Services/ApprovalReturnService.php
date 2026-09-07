<?php

namespace App\Services;

use App\Models\ApprovalReturn;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\DB;

/**
 * Stage 77 — [D] Art. 94 and Appendix 34: the approving body sends a file back.
 *
 * This is [A] §5's Path 3, which gap-analysis §14 flagged as having no
 * equivalent since Stage 57. Art. 94's rule is the whole design: "فلا يعدل
 * المحضر المعتمد بصورة غير رسمية. **بل ينشأ إجراء إعادة معالجة يثبت سبب الإعادة
 * والإجراء الذي اتخذ بشأنها**" — so a return is a recorded action with two
 * halves, never a quiet edit of an approved محضر, and the previous `Decision`
 * row is left exactly as it was.
 *
 * The service owns the rules and both writes. `WorkflowService` stays the sole
 * owner of stage movement: the substantive branch below asks it to perform the
 * move rather than writing `current_stage_id` itself, and the formal branch
 * moves no stage at all.
 */
class ApprovalReturnService
{
    /** Art. 38 code 15 — the file is with عميد البلدية. */
    public const MUNICIPAL_STAGE = 'approval_by_authority';

    /** Art. 38 code 16 — the file is with وزارة الحكم المحلي. */
    public const CENTRAL_STAGE = 'local_governance_ministry';

    /**
     * The two checkpoints at which a file is genuinely "with an approving
     * body", mapped to the status a re-referral restores.
     *
     * Keyed by stage, not by status, because `approval_by_authority` is also
     * legitimately reachable carrying Stage 35's `approved_with_conditions` —
     * gating on the awaiting status alone would strand every conditionally
     * approved file.
     *
     * @var array<string, string>
     */
    public const AWAITING_STATUS_BY_STAGE = [
        self::MUNICIPAL_STAGE => 'awaiting_municipal_approval',
        self::CENTRAL_STAGE => 'awaiting_central_approval',
    ];

    /** Appendix 48's seventh condition, and this stage's own new status. */
    public const RETURNED_STATUS = 'returned_by_approving_body';

    /**
     * Every status that means "this file is inside the approval cycle".
     *
     * Read by RequestVisibility: Art. 30 assigns this register to مقرر اللجنة
     * (R02), who holds no workflow_transitions row at either approval stage, so
     * without it the recorder would 404 on the very file they are meant to
     * record a return for — the fifth instance of the gap Stages 47/68/75/76
     * each closed the same bounded way. `approved` is the legacy status Stage
     * 69 retired but still recognises everywhere it is read.
     *
     * @var list<string>
     */
    public const APPROVAL_CYCLE_STATUSES = [
        'awaiting_municipal_approval',
        'awaiting_central_approval',
        'approved',
        self::RETURNED_STATUS,
    ];

    /**
     * Appendix 34 — "وفي الحالة الموضوعية لا يعدل المقرر القرار من تلقاء نفسه،
     * بل **يعاد الموضوع إلى اللجنة** إذا كانت الملاحظة تمس جوهر قرارها."
     */
    public const SUBSTANTIVE_TARGET_STAGE = 'receive_from_committee';

    /**
     * Art. 78's own إعادة عرض status, reused rather than invented: the article
     * lists "إعادة الموضوع من جهة الاعتماد" among the seven legitimate
     * re-presentation reasons, and Stage 66 already built this status for
     * exactly that class of move (ReopenReasonCatalog's
     * `returned_by_approving_body` code is its other half).
     */
    public const SUBSTANTIVE_TARGET_STATUS = 'reopened_for_representation';

    public function __construct(private readonly WorkflowService $workflow) {}

    /**
     * Why a return may not be recorded against this request, or null if it may.
     *
     * Returns the message rather than a boolean so a refusal reads as an
     * explanation and the screen's "why not" and the endpoint's 422 are the
     * same sentence, computed once — the RequestClosureService convention.
     */
    public function refusalReason(Request $requestRecord): ?string
    {
        if ($this->openReturn($requestRecord) !== null) {
            return 'توجد إعادة من جهة الاعتماد لم يثبت بعد الإجراء المتخذ بشأنها.';
        }

        if (! array_key_exists((string) $requestRecord->currentStage?->code, self::AWAITING_STATUS_BY_STAGE)) {
            return 'لا تثبت الإعادة إلا لمعاملة محالة إلى جهة اعتماد.';
        }

        return null;
    }

    /** The unresolved return, if the file is currently sitting on one. */
    public function openReturn(Request $requestRecord): ?ApprovalReturn
    {
        return $requestRecord->approvalReturns()
            ->whereNull('resolved_at')
            ->latest('id')
            ->first();
    }

    /**
     * Appendix 34 classifies the return; where a reason carries a kind in the
     * source, the two must agree. `other` is free either way, since both of the
     * appendix's lists are examples ("مثل").
     */
    public function kindMismatch(string $kind, string $reasonCode): ?string
    {
        $sourceKind = ApprovalReturn::REASONS[$reasonCode]['kind'] ?? null;

        if ($sourceKind === null || $sourceKind === $kind) {
            return null;
        }

        return 'سبب الإعادة «'.ApprovalReturn::reasonLabel($reasonCode).'» مصنف في الملحق 34 ضمن الإعادة '
            .($sourceKind === ApprovalReturn::KIND_FORMAL ? 'الشكلية' : 'الموضوعية').'.';
    }

    /**
     * Record the return itself — Art. 94's سبب الإعادة half.
     *
     * Status-only: the file has not gone anywhere yet, because Art. 94's
     * re-processing action is what moves it and that action has not been taken.
     * `current_stage_id` therefore stays on the approving body's own
     * checkpoint, which is also what lets a formal resolution re-refer to the
     * same body without a stage move at all.
     *
     * @param  array<string, mixed>  $card
     */
    public function record(Request $requestRecord, User $actor, array $card): ApprovalReturn
    {
        return DB::transaction(function () use ($requestRecord, $actor, $card) {
            $locked = Request::query()
                ->with(['status:id,code', 'currentStage:id,code'])
                ->lockForUpdate()
                ->findOrFail($requestRecord->id);

            // Re-checked inside the lock: the controller's own check ran
            // before it, and the file could have been approved onward since.
            if (($reason = $this->refusalReason($locked)) !== null) {
                throw new \DomainException($reason);
            }

            $returned = RequestStatus::query()->where('code', self::RETURNED_STATUS)->firstOrFail();
            $fromStatusId = $locked->status_id;

            $record = ApprovalReturn::create([
                'request_id' => $locked->id,
                'returned_from_stage_id' => $locked->current_stage_id,
                'return_kind' => $card['return_kind'],
                'return_reason_code' => $card['return_reason_code'],
                'return_note' => $card['return_note'],
                'letter_number' => $card['letter_number'] ?? null,
                'received_at' => $card['received_at'],
                'recorded_by_user_id' => $actor->id,
            ]);

            $locked->update(['status_id' => $returned->id]);

            RequestStatusHistory::create([
                'request_id' => $locked->id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $returned->id,
                'reason' => 'أعيدت من جهة الاعتماد ('
                    .($card['return_kind'] === ApprovalReturn::KIND_FORMAL ? 'إعادة شكلية' : 'إعادة موضوعية')
                    .'): '.ApprovalReturn::reasonLabel($card['return_reason_code']),
                'changed_by_user_id' => $actor->id,
                'changed_at' => now(),
            ]);

            return $record;
        });
    }

    /**
     * Record الإجراء الذي اتخذ بشأنها — Art. 94's second half — and route the
     * file per Appendix 34's split.
     *
     * **شكلية**: the مقرر corrected it himself and re-referred it to the same
     * body, so the awaiting status for the stage it is standing on is restored
     * and no stage moves. That is the appendix's own carve-out: a formal defect
     * does not need the committee again.
     *
     * **موضوعية**: "يعاد الموضوع إلى اللجنة" — WorkflowService::reopenAtStage()
     * performs the move to `receive_from_committee`, writing both audit rows
     * and firing stageChanged() exactly as an ordinary transition would. The
     * previous decision is untouched; the committee records a new one after a
     * fresh nomination, which is Art. 78's إعادة العرض and Art. 94's whole
     * point (an approved محضر is never quietly amended).
     */
    public function resolve(Request $requestRecord, ApprovalReturn $return, User $actor, string $action): Request
    {
        $reasonText = 'إعادة معالجة بعد الإعادة من جهة الاعتماد ('
            .ApprovalReturn::reasonLabel($return->return_reason_code).'): '.$action;

        if ($return->return_kind === ApprovalReturn::KIND_SUBSTANTIVE) {
            $targetStage = WorkflowStage::query()
                ->where('code', self::SUBSTANTIVE_TARGET_STAGE)
                ->firstOrFail();

            $moved = $this->workflow->reopenAtStage(
                $requestRecord,
                $targetStage,
                $actor,
                $reasonText,
                action: 'approval_return_restudy',
                statusCode: self::SUBSTANTIVE_TARGET_STATUS,
            );

            $return->update([
                'resolution_action' => $action,
                'resolution_target_stage_id' => $targetStage->id,
                'resolved_by_user_id' => $actor->id,
                'resolved_at' => now(),
            ]);

            return $moved;
        }

        return DB::transaction(function () use ($requestRecord, $return, $actor, $action, $reasonText) {
            $locked = Request::query()
                ->with('currentStage:id,code')
                ->lockForUpdate()
                ->findOrFail($requestRecord->id);

            $awaitingCode = self::AWAITING_STATUS_BY_STAGE[(string) $locked->currentStage?->code] ?? null;

            if ($awaitingCode === null) {
                throw new \DomainException('لا يمكن إعادة الإحالة إلى جهة الاعتماد من هذه المرحلة.');
            }

            $awaiting = RequestStatus::query()->where('code', $awaitingCode)->firstOrFail();
            $fromStatusId = $locked->status_id;

            $locked->update(['status_id' => $awaiting->id]);

            RequestStatusHistory::create([
                'request_id' => $locked->id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $awaiting->id,
                'reason' => $reasonText,
                'changed_by_user_id' => $actor->id,
                'changed_at' => now(),
            ]);

            $return->update([
                'resolution_action' => $action,
                // No stage moved — the file was re-referred to the same body it
                // came back from, which is the formal branch's whole point.
                'resolution_target_stage_id' => $locked->current_stage_id,
                'resolved_by_user_id' => $actor->id,
                'resolved_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
