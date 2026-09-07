<?php

namespace App\Services;

use App\Exceptions\MeetingOutputTransitionException;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records that a decided meeting request's administrative or financial effect
 * has actually been carried out.
 *
 * This boundary intentionally changes status only. WorkflowService remains the
 * sole owner of stage movement; execution records that work at the final stage
 * has been done, rather than inventing a twelfth stage.
 *
 * Stage 69 (Track K) split Stage 37's single `complete()` in two, because [D]
 * Art. 38 has two codes here and Appendix 5 refuses to let them collapse:
 * "الحالة 19 — منفذة. تم تطبيق الأثر الإداري أو المالي. **لكنها لا تصبح مغلقة
 * إلا بعد التحقق من اكتمال التوثيق**."
 *
 * Stage 75 then moved the second half out of this class entirely. Art. 37's
 * four final paths include two (عدم الموافقة، عدم الاختصاص) that close requests
 * which may never have reached a committee agenda at all, so closure cannot be
 * a meeting-output concern; it lives in RequestClosureService, which is now the
 * sole writer of code 20. This class owns 18 → 19 and nothing further.
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
                throw MeetingOutputTransitionException::notInExecution();
            }

            $target = RequestStatus::query()->where('code', 'executed')->firstOrFail();

            $fromStatusId = $requestRecord->status_id;
            $requestRecord->update(['status_id' => $target->id]);

            RequestStatusHistory::create([
                'request_id' => $requestRecord->id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $target->id,
                'reason' => 'تم تنفيذ الأثر الإداري أو المالي المطلوب.',
                'changed_by_user_id' => $actor->id,
                'changed_at' => now(),
            ]);

            return $requestRecord->refresh();
        });
    }
}
