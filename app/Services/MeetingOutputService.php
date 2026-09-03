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
 */
class MeetingOutputService
{
    /**
     * @throws MeetingOutputTransitionException
     */
    // Stage 37 — execution completion without a parallel workflow stage machine.
    public function complete(MeetingRequest $output, User $actor): Request
    {
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

        return DB::transaction(function () use ($output, $actor) {
            $requestRecord = Request::query()
                ->with(['currentStage:id,code', 'status:id,code'])
                ->lockForUpdate()
                ->findOrFail($output->request_id);

            if ($requestRecord->currentStage?->code !== 'final_approval_archiving') {
                throw MeetingOutputTransitionException::wrongStage();
            }

            if ($requestRecord->status?->code !== 'in_execution') {
                throw MeetingOutputTransitionException::transitionNotAllowed();
            }

            // Stage 59, Track J — [D] Arts. 34–37: the file stays open until
            // every تظلم path against it has concluded. Stage 65 is the one
            // that releases this hold once an appeal reaches notified_closed.
            if (Appeal::openAgainst($requestRecord->id)) {
                throw MeetingOutputTransitionException::appealOpen();
            }

            $completedStatus = RequestStatus::query()
                ->where('code', 'completed_closed')
                ->firstOrFail();

            $fromStatusId = $requestRecord->status_id;
            $requestRecord->update(['status_id' => $completedStatus->id]);

            RequestStatusHistory::create([
                'request_id' => $requestRecord->id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $completedStatus->id,
                'reason' => 'تم توثيق اكتمال تنفيذ مخرج الاجتماع وإغلاق الطلب.',
                'changed_by_user_id' => $actor->id,
                'changed_at' => now(),
            ]);

            return $requestRecord->refresh();
        });
    }
}
