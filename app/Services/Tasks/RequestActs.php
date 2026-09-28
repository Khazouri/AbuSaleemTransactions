<?php

namespace App\Services\Tasks;

use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\User;
use App\Services\ApprovalReferralService;
use App\Services\ApprovalReturnService;
use App\Services\ExecutionSoundnessService;
use App\Services\RequestClosureService;
use App\Services\RequestExecutionService;
use App\Services\RequestSuspensionService;

/**
 * Decision wizard — sub-project 3. Every act the signed-in user may take on a
 * request besides its workflow transitions, for the wizard's Choose step: the
 * ones open now, and the ones held back with the endpoint's own reason.
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
    ) {}

    /**
     * @return array{available: list<array<string, mixed>>, blocked: list<array<string, mixed>>}
     */
    public function forRequest(Request $requestRecord, User $actor): array
    {
        $requestRecord->loadMissing(['status:id,code', 'currentStage:id,code']);
        $acts = ['available' => [], 'blocked' => []];

        $this->afterDecision($acts, $requestRecord, $actor);

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
