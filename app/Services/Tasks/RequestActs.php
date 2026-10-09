<?php

namespace App\Services\Tasks;

use App\Http\Controllers\Api\RequestController;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestWithdrawal;
use App\Models\User;
use App\Services\ApprovalReferralService;
use App\Services\ApprovalReturnService;
use App\Services\EmployeeNoticeService;
use App\Services\ExecutionSoundnessService;
use App\Services\Lifecycle\CorrectionRules;
use App\Services\Lifecycle\WithdrawalService;
use App\Services\RequestClosureService;
use App\Services\RequestExecutionService;
use App\Services\RequestSuspensionService;
use Illuminate\Support\Str;

/**
 * Decision wizard — sub-project 3. Every act the signed-in user may take on a
 * request besides its workflow transitions, for the wizard's Choose step: the
 * ones open now, and the ones held back with the endpoint's own reason. Three
 * families: after_decision (post-decision execution/closure/return work),
 * records (the file's own open records — conflicts, corrections, withdrawals)
 * and file (the notice, financial flag, reopen, notes and attachments).
 *
 * MeetingDuties' three parts again: an act needs its route's grant (else it
 * is "not yours") and its moment (else "not now"), and either absence leaves
 * it out; inside both, a refusal is reported as blocked. Every refusal read
 * here is the method the endpoint itself calls, so the two cannot disagree.
 */
class RequestActs
{
    public function __construct(
        private readonly ApprovalReferralService $referrals,
        private readonly ApprovalReturnService $returns,
        private readonly RequestClosureService $closure,
        private readonly RequestExecutionService $execution,
        private readonly RequestSuspensionService $suspensions,
        private readonly CorrectionRules $corrections,
        private readonly EmployeeNoticeService $notices,
        private readonly WithdrawalService $withdrawals,
    ) {}

    /**
     * @return array{available: list<array<string, mixed>>, blocked: list<array<string, mixed>>}
     */
    public function forRequest(Request $requestRecord, User $actor): array
    {
        $requestRecord->loadMissing(['status:id,code', 'currentStage:id,code']);
        $acts = ['available' => [], 'blocked' => []];

        $this->afterDecision($acts, $requestRecord, $actor);
        $this->records($acts, $requestRecord, $actor);
        $this->fileActs($acts, $requestRecord, $actor);

        return $acts;
    }

    /** @param  array{available: list<array<string, mixed>>, blocked: list<array<string, mixed>>}  $acts */
    private function afterDecision(array &$acts, Request $r, User $actor): void
    {
        $status = (string) $r->status?->code;
        $stage = (string) $r->currentStage?->code;
        $edit = $actor->hasScreenPermission('meeting_outputs', 'can_edit');
        $approve = $actor->hasScreenPermission('meeting_outputs', 'can_approve');
        $open = $r->closed_at === null;
        $closable = $open && in_array($status, RequestClosureService::CLOSABLE_STATUSES, true);

        // Art. 103 — "قبل إحالة النتيجة للتنفيذ", so only while final_approved.
        if ($edit && $stage === ExecutionSoundnessService::GATED_STAGE && $status === 'final_approved') {
            $this->offer($acts, 'after_decision', 'execution_soundness', null);
        }

        // markExecuted() refuses anything short of in_execution with its own
        // message, so outside that state execute is "not now", never blocked.
        if ($approve && $stage === 'final_approval_archiving' && $status === RequestExecutionService::EXECUTABLE_STATUS
            && $r->executed_at === null && ($item = $this->decidedItem($r)) !== null) {
            $this->offer($acts, 'after_decision', 'execute', $this->execution->refusalReason($r), [
                'target' => ['id' => $item->id, 'meeting_id' => $item->meeting_id, 'label' => null],
                'has_financial_impact' => (bool) $r->has_financial_impact,
            ]);
        }

        if ($edit && $closable) {
            $this->offer($acts, 'after_decision', 'archive_committee_file', $this->closure->archiveRefusal($r));
        }

        if ($actor->hasScreenPermission('meeting_outputs', 'can_add') && $closable && $this->closure->requiresServiceFileArchive($r)) {
            $this->offer($acts, 'after_decision', 'archive_service_file', $this->closure->archiveRefusal($r));
        }

        // A file Appendix 48 names by status is listed too, so the closer is
        // told why it cannot close yet rather than finding the act missing.
        if ($approve && $open && ($closable || isset(RequestClosureService::BLOCKING_STATUSES[$status]))) {
            $this->offer($acts, 'after_decision', 'close', $this->closure->refusalReason($r));
        }

        if ($edit && array_key_exists($stage, ApprovalReturnService::AWAITING_STATUS_BY_STAGE)) {
            $this->offer($acts, 'after_decision', 'record_approval_return', $this->returns->refusalReason($r));
        }

        if ($edit && ($return = $this->returns->openReturn($r)) !== null) {
            $this->offer($acts, 'after_decision', 'resolve_approval_return', $this->returns->resolveRefusal($r), [
                'return_kind' => $return->return_kind,
            ]);
        }

        if ($edit && in_array($status, ApprovalReferralService::REFERRABLE_STATUSES, true)) {
            $this->offer($acts, 'after_decision', 'record_approval_referral', $this->referrals->refusalReason($r));
        }

        // A loop, not ->each(fn …): an arrow function would capture $acts by value.
        foreach ($edit ? $r->approvalReferrals()->whereNull('result_outcome')->orderBy('id')->get() : [] as $referral) {
            $this->offer($acts, 'after_decision', 'record_referral_result', null, [
                'target' => ['id' => $referral->id, 'label' => $referral->letter_number.' — '.$referral->referred_to_body],
            ]);
        }

        if ($edit && in_array($status, RequestSuspensionService::SUSPENDABLE_STATUSES, true)) {
            $this->offer($acts, 'after_decision', 'suspend', $this->suspensions->refusalReason($r));
        }

        if ($edit && ($suspension = $this->suspensions->openSuspension($r)) !== null) {
            $this->offer($acts, 'after_decision', 'lift_suspension', $this->suspensions->liftRefusalReason($r, $suspension));
        }
    }

    /** The agenda item whose decision is executed — the latest one decided, as the inbox picks it. */
    private function decidedItem(Request $r): ?MeetingRequest
    {
        return $r->meetingRequests()->whereHas('decision')->latest('id')->first(['id', 'meeting_id']);
    }

    /**
     * The file's own records — Appendices 30, 60, 53 and 68/69. Resolving
     * acts are one per open row, so the wizard and the inbox can name the row.
     *
     * @param  array{available: list<array<string, mixed>>, blocked: list<array<string, mixed>>}  $acts
     */
    private function records(array &$acts, Request $r, User $actor): void
    {
        $edit = $actor->hasScreenPermission('meeting_outputs', 'can_edit');

        if ($edit && $r->closed_at === null) {
            $this->offer($acts, 'records', 'record_document_conflict', null);
            $this->offer($acts, 'records', 'record_special_case', null);
        }

        // Appendix 53 — a correction memo may follow closure.
        if ($edit) {
            $this->offer($acts, 'records', 'record_correction', null);

            foreach ($r->documentConflicts()->whereNull('resolved_at')->orderBy('id')->get() as $conflict) {
                $this->offer($acts, 'records', 'resolve_document_conflict', null, [
                    'target' => ['id' => $conflict->id, 'kind' => $conflict->conflict_kind, 'label' => Str::limit($conflict->detail, 60)],
                ]);
            }

            foreach ($r->specialCases()->whereNull('resolved_at')->orderBy('id')->get() as $case) {
                $this->offer($acts, 'records', 'resolve_special_case', null, [
                    'target' => ['id' => $case->id, 'kind' => $case->case_kind, 'label' => $case->detail ? Str::limit($case->detail, 60) : null],
                ]);
            }

            foreach ($r->corrections()->whereNull('approved_at')->orderBy('id')->get() as $correction) {
                $this->offer($acts, 'records', 'approve_correction', $this->corrections->approvalRefusal($correction, $actor), [
                    'target' => [
                        'id' => $correction->id,
                        'kind' => $correction->error_kind,
                        'label' => $correction->incorrect_value.' ← '.$correction->corrected_value,
                    ],
                ]);
            }

            foreach ($r->withdrawals()->whereNull('determined_at')->orderBy('id')->get() as $withdrawal) {
                $this->offer($acts, 'records', 'determine_withdrawal', null, [
                    'target' => ['id' => $withdrawal->id, 'label' => Str::limit($withdrawal->reason, 60)],
                    // Appendix 69 decides which outcomes exist; asking the
                    // endpoint's own refusal keeps the choice list honest.
                    'outcomes' => array_values(array_filter(
                        array_keys(RequestWithdrawal::OUTCOMES),
                        fn (string $outcome) => $this->withdrawals->refusalReason($r, $withdrawal, $outcome) === null,
                    )),
                ]);
            }
        }

        // "إذا طلب الموظف سحب معاملته" — the filer's own act, never anyone else's.
        if ($r->created_by_user_id === $actor->id && $actor->hasScreenPermission('notes_attachments', 'can_add')) {
            $this->offer($acts, 'records', 'file_withdrawal', $this->withdrawals->filingRefusal($r));
        }
    }

    /**
     * What is written onto the file itself: the notice, the financial flag,
     * a reopen, a note, a document.
     *
     * @param  array{available: list<array<string, mixed>>, blocked: list<array<string, mixed>>}  $acts
     */
    private function fileActs(array &$acts, Request $r, User $actor): void
    {
        if ($actor->hasScreenPermission('meeting_outputs', 'can_edit')) {
            $this->offer($acts, 'file', 'issue_notice', $this->notices->issueRefusal($r));
        }

        if ($actor->hasScreenPermission('notes_attachments', 'can_edit')) {
            $this->offer($acts, 'file', 'set_financial_impact', null, ['has_financial_impact' => (bool) $r->has_financial_impact]);
        }

        if ($actor->hasScreenPermission('appeals', 'can_edit')
            && in_array($r->status?->code, RequestController::REOPENABLE_STATUS_CODES, true)) {
            $this->offer($acts, 'file', 'reopen', RequestController::reopenRefusal($r, $actor));
        }

        if ($actor->hasScreenPermission('notes_attachments', 'can_add')) {
            $this->offer($acts, 'file', 'add_note', null);

            if ($r->attachmentRight($actor) !== null) {
                $this->offer($acts, 'file', 'attach_document', $r->attachmentRefusal());
            }
        }
    }

    /**
     * @param  array{available: list<array<string, mixed>>, blocked: list<array<string, mixed>>}  $acts
     * @param  array<string, mixed>  $extra
     */
    private function offer(array &$acts, string $family, string $action, ?string $refusal, array $extra = []): void
    {
        $entry = ['action' => $action, 'family' => $family, 'target' => null, ...$extra];

        if ($refusal === null) {
            $acts['available'][] = $entry;
        } else {
            $acts['blocked'][] = [...$entry, 'reason' => $refusal];
        }
    }
}
