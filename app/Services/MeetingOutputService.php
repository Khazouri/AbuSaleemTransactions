<?php

namespace App\Services;

use App\Exceptions\MeetingOutputTransitionException;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\User;
use DomainException;
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
 *
 * Stage 76 gave 18 → 19 the evidence Appendix 70 demands. Execution stays
 * meeting-scoped where closure could not: `markExecuted()` requires a persisted
 * agenda item carrying a recorded decision, so there is no execution path that
 * bypassed a committee the way a Stage 54 pre-committee عدم اختصاص does.
 */
class MeetingOutputService
{
    public function __construct(private readonly RequestExecutionService $rules) {}

    /**
     * [D] Art. 38 code 18 → 19. The administrative or financial effect has
     * been applied; the file is not closed yet.
     *
     * Appendix 70's "لا يكفي أن تقول الجهة المنفذة (تم التنفيذ) بل يجب إرفاق
     * دليل التنفيذ" is enforced here: the نموذج 17 card, its متابعة التنفيذ
     * checks and at least one nominated evidence document are written in the
     * same transaction as the status move, and RequestExecutionService owns the
     * rules so this method and the screen's "why not" cannot disagree.
     *
     * @param  array<string, mixed>  $card  النموذج 17's human-supplied fields
     * @param  array<string, string>  $checklist  its six executor answers
     * @param  list<array{attachment_id: int, evidence_type: string}>  $evidence  Appendix 70's دليل التنفيذ
     *
     * @throws MeetingOutputTransitionException|DomainException
     */
    public function markExecuted(
        MeetingRequest $output,
        User $actor,
        array $card,
        array $checklist,
        array $evidence,
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

        return DB::transaction(function () use ($output, $actor, $card, $checklist, $evidence) {
            $requestRecord = Request::query()
                ->with(['currentStage:id,code', 'status:id,code'])
                ->lockForUpdate()
                ->findOrFail($output->request_id);

            if ($requestRecord->currentStage?->code !== 'final_approval_archiving') {
                throw MeetingOutputTransitionException::wrongStage();
            }

            if ($requestRecord->status?->code !== RequestExecutionService::EXECUTABLE_STATUS) {
                throw MeetingOutputTransitionException::notInExecution();
            }

            $attachmentIds = array_values(array_unique(array_map(
                static fn (array $item): int => (int) $item['attachment_id'],
                $evidence,
            )));

            // Re-checked inside the lock: the controller's own check ran before
            // it, and a document could have been removed in between.
            if (($reason = $this->rules->refusalReason($requestRecord, $checklist, $attachmentIds)) !== null) {
                throw new DomainException($reason);
            }

            // Appendix 70's evidence is marked on the request's own documents,
            // so "تم إرفاق مستند التنفيذ" is afterwards a fact about storage
            // rather than a claim in a form. Written before the checklist is
            // recorded so a rolled-back transaction leaves neither behind.
            foreach ($evidence as $item) {
                $requestRecord->attachments()
                    ->whereKey($item['attachment_id'])
                    ->update(['execution_evidence_type' => $item['evidence_type']]);
            }

            $target = RequestStatus::query()->where('code', 'executed')->firstOrFail();

            $fromStatusId = $requestRecord->status_id;

            $requestRecord->update([
                'status_id' => $target->id,
                // النموذج 17's card, less the employee, the request number and
                // the decision — all three are already known, and asking a
                // human to retype an answer the system holds is the error
                // Stage 75's computed `final_result_code` avoided.
                'execution' => [
                    'executing_body' => $card['executing_body'],
                    'action_taken' => $card['action_taken'],
                    'effective_date' => $card['effective_date'],
                    'approving_body' => $card['approving_body'],
                    'approval_number' => $card['approval_number'] ?? null,
                    'approval_date' => $card['approval_date'] ?? null,
                    'financial_effect_note' => $card['financial_effect_note'] ?? null,
                ],
                'execution_checklist' => $this->rules->checklistRecord($checklist, $attachmentIds),
                'executed_by_user_id' => $actor->id,
                'executed_at' => now(),
            ]);

            RequestStatusHistory::create([
                'request_id' => $requestRecord->id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $target->id,
                'reason' => 'تم تنفيذ الأثر الإداري أو المالي المطلوب وأرفق دليل التنفيذ.',
                'changed_by_user_id' => $actor->id,
                'changed_at' => now(),
            ]);

            return $requestRecord->refresh();
        });
    }
}
