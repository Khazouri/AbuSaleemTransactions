<?php

namespace App\Services;

use App\Exceptions\MeetingOutputTransitionException;
use App\Models\Appeal;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closes the execution loop for a decided meeting request.
 *
 * This boundary intentionally changes status only. WorkflowService remains the
 * sole owner of stage movement; completion records that work at the final stage
 * has actually been carried out, rather than inventing a twelfth stage.
 *
 * Stage 69 (Track K) split Stage 37's single `complete()` in two, because [D]
 * Art. 38 has two codes here and Appendix 5 refuses to let them collapse:
 * "الحالة 19 — منفذة. تم تطبيق الأثر الإداري أو المالي. **لكنها لا تصبح مغلقة
 * إلا بعد التحقق من اكتمال التوثيق**." Recording that the effect was carried
 * out (19) and certifying that the file is documented and may be archived (20)
 * are two distinct acts, so they are two distinct calls.
 */
class MeetingOutputService
{
    /**
     * [D] Art. 38 code 18 → 19. The administrative or financial effect has
     * been applied; the file is not closed yet.
     *
     * Appendix 70's "لا يكفي أن تقول الجهة المنفذة (تم التنفيذ) بل يجب إرفاق
     * دليل التنفيذ" belongs on this action — that evidence requirement is
     * Stage 76's own scope and is deliberately not half-built here.
     *
     * @throws MeetingOutputTransitionException
     */
    public function markExecuted(MeetingRequest $output, User $actor): Request
    {
        return $this->move(
            $output,
            $actor,
            fromStatus: 'in_execution',
            toStatus: 'executed',
            reason: 'تم تنفيذ الأثر الإداري أو المالي المطلوب.',
            onWrongStatus: MeetingOutputTransitionException::notInExecution(...),
            enforceAppealHold: false,
        );
    }

    /**
     * [D] Art. 38 code 19 → 20, the formal end of the request's life cycle.
     *
     * The appeal hold lives here and not on markExecuted(): Arts. 34–37 keep
     * the *file* open until every تظلم path against it has concluded, which is
     * a statement about closure, not about whether the effect was carried out.
     *
     * @throws MeetingOutputTransitionException
     */
    public function close(MeetingRequest $output, User $actor): Request
    {
        return $this->move(
            $output,
            $actor,
            fromStatus: 'executed',
            toStatus: 'completed_closed',
            reason: 'تم التحقق من اكتمال التوثيق وإقفال المعاملة وأرشفتها.',
            onWrongStatus: MeetingOutputTransitionException::notExecuted(...),
            enforceAppealHold: true,
        );
    }

    /**
     * @param  callable(): MeetingOutputTransitionException  $onWrongStatus
     *
     * @throws MeetingOutputTransitionException
     */
    private function move(
        MeetingRequest $output,
        User $actor,
        string $fromStatus,
        string $toStatus,
        string $reason,
        callable $onWrongStatus,
        bool $enforceAppealHold,
    ): Request {
        if (! $output->exists) {
            throw MeetingOutputTransitionException::outputNotPersisted();
        }

        if (! $actor->exists || ! $actor->is_active) {
            throw MeetingOutputTransitionException::actorNotActive();
        }

        if ($output->request_id === null) {
            throw MeetingOutputTransitionException::requestRequired();
        }

        if (! $output->decision()->exists()) {
            throw MeetingOutputTransitionException::decisionRequired();
        }

        return DB::transaction(function () use (
            $output, $actor, $fromStatus, $toStatus, $reason, $onWrongStatus, $enforceAppealHold
        ) {
            $requestRecord = Request::query()
                ->with(['currentStage:id,code', 'status:id,code'])
                ->lockForUpdate()
                ->findOrFail($output->request_id);

            if ($requestRecord->currentStage?->code !== 'final_approval_archiving') {
                throw MeetingOutputTransitionException::wrongStage();
            }

            if ($requestRecord->status?->code !== $fromStatus) {
                throw $onWrongStatus();
            }

            // Stage 59, Track J — [D] Arts. 34–37: the file stays open until
            // every تظلم path against it has concluded. Stage 65 is the one
            // that releases this hold once an appeal reaches notified_closed.
            if ($enforceAppealHold && Appeal::openAgainst($requestRecord->id)) {
                throw MeetingOutputTransitionException::appealOpen();
            }

            $target = RequestStatus::query()->where('code', $toStatus)->firstOrFail();

            $fromStatusId = $requestRecord->status_id;
            $requestRecord->update(['status_id' => $target->id]);

            RequestStatusHistory::create([
                'request_id' => $requestRecord->id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $target->id,
                'reason' => $reason,
                'changed_by_user_id' => $actor->id,
                'changed_at' => now(),
            ]);

            return $requestRecord->refresh();
        });
    }
}
