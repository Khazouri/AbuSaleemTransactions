<?php

namespace App\Services;

use App\Models\ApprovalReferral;
use App\Models\Request;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Stage 80 — [D] Art. 30's register of referrals to an approving body, i.e.
 * Art. 98's register 7 (سجل الإحالات للاعتماد).
 *
 * The article names six recorded fields and Stage 77 built two of them, but
 * only on the *return* path — a file that comes back approved travels the
 * ordinary `approve` transition and records nothing at all, so رقم قرار
 * الاعتماد had no home anywhere in the schema and neither did the three
 * outward fields. Stage 77's own note asked whichever stage built this
 * register to take all four together rather than fragment the article.
 *
 * **This is a register, not a gate.** Art. 30 says "ويسجل مقرر اللجنة", not
 * "ولا يحال قبل"; nothing here refuses an approval transition because no
 * referral was recorded. Appendix 63's four control gates are Stage 78's, and
 * inventing a fifth would be adding a requirement the source does not state.
 *
 * The two writes exist because the article names an outward moment and an
 * inward one, and nobody knows the second at the moment of the first — the
 * same shape, and the same reasoning, as Stage 77's ApprovalReturnService.
 */
class ApprovalReferralService
{
    /**
     * The statuses at which a file is genuinely out with an approving body, or
     * about to be.
     *
     * Deliberately wider than Stage 77's gate: a referral may be recorded on
     * `returned_by_approving_body` (a corrected formal return being re-referred
     * is literally a second إحالة, which is exactly what Appendix 34's شكلية
     * branch describes) and on `final_approved`, so a مقرر who is recording the
     * register after the fact can still enter the referral the approval it
     * already carries came from.
     *
     * @var list<string>
     */
    public const REFERRABLE_STATUSES = [
        'awaiting_municipal_approval',
        'awaiting_central_approval',
        'approved',
        'returned_by_approving_body',
        'final_approved',
    ];

    /** Why a referral may not be recorded here, or null if it may. */
    public function refusalReason(Request $requestRecord): ?string
    {
        if (! in_array((string) $requestRecord->status?->code, self::REFERRABLE_STATUSES, true)) {
            return 'لا تثبت الإحالة للاعتماد إلا لمعاملة داخل دورة الاعتماد.';
        }

        return null;
    }

    /** The referral still awaiting the approving body's answer, if any. */
    public function openReferral(Request $requestRecord): ?ApprovalReferral
    {
        return $requestRecord->approvalReferrals()
            ->whereNull('result_outcome')
            ->latest('id')
            ->first();
    }

    /**
     * Art. 30's outward three — تاريخ الإحالة · رقم كتاب الإحالة · الجهة
     * المحال إليها.
     *
     * Records only; it moves no stage and no status. The file's own position
     * is already whatever the approve transition put it at, and re-stating it
     * here would give the same fact two writers that can disagree.
     *
     * @param  array<string, mixed>  $card
     */
    public function record(Request $requestRecord, User $actor, array $card): ApprovalReferral
    {
        return DB::transaction(function () use ($requestRecord, $actor, $card) {
            $locked = Request::query()
                ->with(['status:id,code'])
                ->lockForUpdate()
                ->findOrFail($requestRecord->id);

            // Re-checked inside the lock, like every other Track K write: the
            // controller's own check ran before it and the file may have moved.
            if (($reason = $this->refusalReason($locked)) !== null) {
                throw new DomainException($reason);
            }

            if ($this->openReferral($locked) !== null) {
                throw new DomainException('توجد إحالة للاعتماد لم تثبت نتيجتها بعد.');
            }

            return ApprovalReferral::create([
                'request_id' => $locked->id,
                'referred_from_stage_id' => $locked->current_stage_id,
                'referred_at' => $card['referred_at'],
                'letter_number' => $card['letter_number'],
                'referred_to_body' => $card['referred_to_body'],
                'recorded_by_user_id' => $actor->id,
            ]);
        });
    }

    /**
     * Art. 30's inward three — تاريخ ورود النتيجة · رقم قرار الاعتماد أو
     * المستند النهائي · أي ملاحظات أو توجيهات صادرة عن جهة الاعتماد.
     *
     * `approval_decision_number` is required only for an `approved` outcome:
     * a file that came back returned has no اعتماد decision number to record,
     * and demanding one would make the honest answer unrecordable.
     *
     * A returned outcome deliberately writes nothing beyond this row — the
     * return's own reason and its re-processing stay in Stage 77's
     * `approval_returns`, which is Art. 98's separate register 8. Recording
     * one here too would give a return two sources of truth.
     *
     * @param  array<string, mixed>  $card
     */
    public function recordResult(ApprovalReferral $referral, User $actor, array $card): ApprovalReferral
    {
        return DB::transaction(function () use ($referral, $actor, $card) {
            $locked = ApprovalReferral::query()->lockForUpdate()->findOrFail($referral->id);

            if (! $locked->isOpen()) {
                throw new DomainException('سبق إثبات نتيجة هذه الإحالة.');
            }

            $locked->update([
                'result_received_at' => $card['result_received_at'],
                'approval_decision_number' => $card['approval_decision_number'] ?? null,
                'result_note' => $card['result_note'] ?? null,
                'result_outcome' => $card['result_outcome'],
                'result_recorded_by_user_id' => $actor->id,
                'result_recorded_at' => now(),
            ]);

            return $locked;
        });
    }
}
