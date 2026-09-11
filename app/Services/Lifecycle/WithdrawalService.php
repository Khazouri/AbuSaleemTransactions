<?php

namespace App\Services\Lifecycle;

use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\RequestWithdrawal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stage 83 — [D] Appendices 68 (سحب الموظف لطلبه) and 69 (انسحاب الطلب بعد
 * صدور قرار اللجنة).
 *
 * One record with three outcomes, because the two appendices describe one act
 * whose consequence depends on a fact nobody chooses: whether the committee
 * had already decided. That fact is snapshotted at filing time, so a later
 * re-presentation cannot retroactively change what this round was.
 *
 * **Appendix 69 is enforced as a refusal, not as guidance.** "**بعد صدور نتيجة
 * اللجنة لا يحذف القرار ولا تمحى المعاملة**" — so once any decision exists on
 * the file, `granted` (the outcome that closes it) is simply not available;
 * the only outcome left is `recorded_only`, which records the request and its
 * legal effect and changes no status at all, leaving "المحضر الأصلي محفوظًا
 * باعتباره وثيقة رسمية لما وقع بالفعل" exactly as it was.
 *
 * **A granted withdrawal closes onto `cancelled` rather than a new status, and
 * that is a deliberate call.** Art. 38's twenty codes have none for a
 * withdrawal, and Appendix 68 names a closure *reason* — "تقفل المعاملة بسبب
 * **(سحب الطلب)**" — not a state. `cancelled` (ملغاة) is already this system's
 * "stopped without a decision" state, which every terminal-status consumer
 * handles correctly; minting a fourteenth code would re-open Stage 69's own
 * blast radius across eleven call sites for no sourced gain. The reason lives
 * on the withdrawal row and is quoted into the status-history row, which is
 * where a register reads it from.
 *
 * Appendix 68's step 3 — "تتحقق الجهة من عدم وجود سبب قانوني يستوجب استمرار
 * الإجراء تلقائيًا من الإدارة" — is not a separate tick: choosing between
 * `granted` and `refused_administrative_continuation` **is** that check, and
 * the second outcome is the appendix's own closing paragraph.
 */
class WithdrawalService
{
    /** Filed by the employee; nothing is determined yet. */
    public function file(Request $requestRecord, string $reason, User $actor): RequestWithdrawal
    {
        return $requestRecord->withdrawals()->create([
            'reason' => $reason,
            'requested_by_user_id' => $actor->getKey(),
            'requested_at' => now(),
            'decision_existed_at_filing' => $this->hasDecision($requestRecord),
        ]);
    }

    public function openWithdrawal(Request $requestRecord): ?RequestWithdrawal
    {
        return $requestRecord->withdrawals()->whereNull('determined_at')->latest('id')->first();
    }

    /** Whether the committee has recorded a decision on any of this file's agenda appearances. */
    public function hasDecision(Request $requestRecord): bool
    {
        return $requestRecord->meetingRequests()->whereHas('decision')->exists();
    }

    /**
     * The Arabic reason an outcome may not be recorded on this withdrawal, or
     * null.
     */
    public function refusalReason(Request $requestRecord, RequestWithdrawal $withdrawal, string $outcome): ?string
    {
        if ($withdrawal->determined_at !== null) {
            return 'سبق البت في طلب السحب.';
        }

        $decided = $withdrawal->decision_existed_at_filing || $this->hasDecision($requestRecord);

        if ($outcome === RequestWithdrawal::OUTCOME_GRANTED && $decided) {
            return 'بعد صدور نتيجة اللجنة لا يحذف القرار ولا تمحى المعاملة؛ يسجل طلب الموظف ويحدد أثره القانوني.';
        }

        if ($outcome === RequestWithdrawal::OUTCOME_RECORDED_ONLY && ! $decided) {
            return 'تسجيل الطلب دون أثر يقتصر على ما بعد صدور نتيجة اللجنة.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function determine(Request $requestRecord, RequestWithdrawal $withdrawal, array $data, User $actor): RequestWithdrawal
    {
        return DB::transaction(function () use ($requestRecord, $withdrawal, $data, $actor) {
            $locked = RequestWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->getKey());

            abort_if($locked->determined_at !== null, 422, 'سبق البت في طلب السحب.');

            $locked->update([
                'outcome' => $data['outcome'],
                'determination_note' => $data['determination_note'],
                'determined_by_user_id' => $actor->getKey(),
                'determined_at' => now(),
            ]);

            if ($data['outcome'] === RequestWithdrawal::OUTCOME_GRANTED) {
                $this->closeOnWithdrawal($requestRecord, $locked, $actor);
            }

            return $locked->refresh();
        });
    }

    /**
     * Appendix 68 step 4 — the file is closed and the reason is the
     * appendix's own words.
     *
     * Status-only: nothing about a withdrawal moves the file to another desk,
     * so no stage log is written, the same shape Stage 77's return and Stage
     * 78's suspension both use.
     */
    private function closeOnWithdrawal(Request $requestRecord, RequestWithdrawal $withdrawal, User $actor): void
    {
        $locked = Request::query()->lockForUpdate()->findOrFail($requestRecord->getKey());
        $cancelled = RequestStatus::query()->where('code', 'cancelled')->firstOrFail();
        $fromStatusId = $locked->status_id;

        $locked->update(['status_id' => $cancelled->id]);

        RequestStatusHistory::create([
            'request_id' => $locked->id,
            'from_status_id' => $fromStatusId,
            'to_status_id' => $cancelled->id,
            'reason' => RequestWithdrawal::CLOSURE_REASON.' — '.$withdrawal->reason,
            'changed_by_user_id' => $actor->getKey(),
            'changed_at' => now(),
        ]);

        $requestRecord->setRelation('status', $cancelled);
        $requestRecord->status_id = $cancelled->id;
    }
}
