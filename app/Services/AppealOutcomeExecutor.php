<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Appeal;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\User;
use App\Models\WorkflowStage;

/**
 * Stage 64, Track J — gives Stage 63's already-recorded appeal outcome its
 * real effect on the *original*, decided Request, a deliberately separate
 * step from recording the vote itself (see AppealController::executeOutcome,
 * the only caller, and AGENT_NOTES.md's Stage 64 plan entry for the full
 * reasoning).
 *
 * Three of the five outcomes are a direct status mutation on the SAME
 * Request row — never a new one, per [D] Arts. 34–37's إعادة العرض rule —
 * with no WorkflowService involvement at all: قبول التظلم/قبول جزئي close
 * the matter with a new terminal status (the appeal body's own decision IS
 * the final word — Stage 63's own `comment` field already carries the
 * withdrawal/amendment reasoning, so nothing further needs deciding).
 * إحالة لجهة أخرى reuses the exact status an ordinary committee referral
 * decision already uses (Stage 35/49's `referred_to_other_body`) — a
 * self-loop, not terminal, since the matter can still be re-decided once the
 * other body replies. رفض مسبب needs no execution at all: nothing about the
 * original Request changes.
 *
 * إعادة اإلجراءات (`appeal_redo`) is the one genuinely novel case flagged in
 * STAGE_PLAN.md: it must re-enter the original Request's own WorkflowService
 * state machine at the SPECIFIC stage the legal review found the defect —
 * not restart from scratch, and not simply "back to committee". See
 * WorkflowService::reopenAtStage() for the actual re-entry mechanism.
 */
class AppealOutcomeExecutor
{
    public function __construct(private readonly WorkflowService $workflow) {}

    /**
     * Must run inside the caller's own DB transaction alongside the Appeal's
     * own outcome_executed_* stamp — reopenAtStage() opens its own nested
     * transaction for the Request-side writes, but the two together are
     * only atomic as a pair if the caller wraps both.
     *
     * @throws WorkflowTransitionException
     */
    public function execute(Appeal $appeal, string $outcome, User $actor, ?WorkflowStage $redoStage): void
    {
        if ($outcome === 'appeal_redo' && $redoStage === null) {
            throw new \InvalidArgumentException('appeal_redo execution requires a resolved redo stage.');
        }

        $originalRequest = $appeal->originalRequest;

        match ($outcome) {
            'appeal_accept' => $this->setStatus($originalRequest, 'decision_withdrawn', $actor, $appeal),
            'appeal_partial_accept' => $this->setStatus($originalRequest, 'decision_amended', $actor, $appeal),
            'appeal_refer' => $this->setStatus($originalRequest, 'referred_to_other_body', $actor, $appeal),
            'appeal_reject' => null,
            'appeal_redo' => $this->workflow->reopenAtStage(
                $originalRequest,
                $redoStage,
                $actor,
                'إعادة الإجراءات بناءً على قرار اللجنة بشأن التظلم رقم '.$appeal->id,
            ),
            default => throw new \InvalidArgumentException("Unknown appeal outcome for execution: {$outcome}"),
        };
    }

    private function setStatus(Request $requestRecord, string $statusCode, User $actor, Appeal $appeal): void
    {
        $locked = Request::query()->lockForUpdate()->findOrFail($requestRecord->getKey());

        $fromStatusId = $locked->status_id;
        $statusId = RequestStatus::query()->where('code', $statusCode)->value('id');

        $locked->status_id = $statusId;
        $locked->save();

        RequestStatusHistory::create([
            'request_id' => $locked->id,
            'from_status_id' => $fromStatusId,
            'to_status_id' => $statusId,
            'reason' => 'نتيجة تنفيذ قرار اللجنة بشأن التظلم رقم '.$appeal->id,
            'changed_by_user_id' => $actor->id,
            'changed_at' => now(),
        ]);
    }
}
