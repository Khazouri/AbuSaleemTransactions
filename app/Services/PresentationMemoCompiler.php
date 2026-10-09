<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Decision;
use App\Models\MeetingRequest;
use App\Models\Request;

/**
 * Stage 46 — the *derived* half of a presentation memo's content ([D]
 * Art. 22's fields that are honestly computable from existing data). The
 * four authored fields (facts_summary, employment_status_notes,
 * legal_opinion, committee_question) have no backing data anywhere in this
 * schema and are deliberately not produced here — see
 * PresentationMemoController for where/how they're seeded and preserved.
 */
class PresentationMemoCompiler
{
    /** @return array<string, mixed> */
    public function compile(MeetingRequest $agendaItem): array
    {
        $agendaItem->loadMissing([
            'request.department:id,name_ar,name_en',
            // Stage 95 — Art. 22's الموظف and جهة عمله are صاحب العلاقة's,
            // which is the filer on an ordinary self-filed intake.
            'request.subject:id,name,department_id',
            'request.subject.department:id,name_ar,name_en',
            'request.attachments',
        ]);

        $requestRecord = $agendaItem->request;
        $employee = $requestRecord->subject;

        return [
            'reference_number' => $requestRecord->reference_number,
            'employee' => $employee ? ['id' => $employee->id, 'name' => $employee->name] : null,
            // جهة عمله — the employee's own department, distinct from the
            // request's own department below even though intake usually
            // sets both the same today.
            'work_unit' => $employee?->department ? [
                'id' => $employee->department->id,
                'name_ar' => $employee->department->name_ar,
                'name_en' => $employee->department->name_en,
            ] : null,
            'subject' => $requestRecord->title,
            'submission_date' => $requestRecord->submitted_at?->toIso8601String(),
            // الجهة المحيلة — the request's own department, i.e. the unit
            // this matter was routed through, not necessarily the same as
            // the employee's own work unit above.
            'referring_body' => $requestRecord->department ? [
                'id' => $requestRecord->department->id,
                'name_ar' => $requestRecord->department->name_ar,
                'name_en' => $requestRecord->department->name_en,
            ] : null,
            'key_documents' => $requestRecord->attachments->map(fn (Attachment $attachment) => [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
                'label' => $attachment->label,
            ])->values()->all(),
            'prior_decisions' => $this->priorDecisions($agendaItem, $requestRecord),
        ];
    }

    /**
     * القرارات أو الإجراءات السابقة المتعلقة بالموضوع — scoped to this same
     * request's own earlier committee appearances (a request can revisit
     * receive_from_committee via the defer/legal_opinion/refer_other_body
     * self-loops), not the employee's other, unrelated requests — that
     * broader case is already the live runner's separate "previous_requests"
     * tab (MeetingController::agendaItemContext()).
     *
     * @return array<int, array<string, mixed>>
     */
    private function priorDecisions(MeetingRequest $agendaItem, Request $requestRecord): array
    {
        return Decision::query()
            ->whereHas('meetingRequest', fn ($query) => $query->where('request_id', $requestRecord->id))
            ->where('meeting_request_id', '!=', $agendaItem->id)
            ->with(['meetingRequest.meeting:id,title,scheduled_at', 'decidedBy:id,name'])
            ->orderByDesc('decided_at')
            ->get()
            ->map(fn (Decision $decision) => [
                'id' => $decision->id,
                'outcome' => $decision->outcome,
                'comment' => $decision->comment,
                'decided_by' => $decision->decidedBy?->name,
                'decided_at' => $decision->decided_at?->toIso8601String(),
                'meeting' => $decision->meetingRequest?->meeting ? [
                    'id' => $decision->meetingRequest->meeting->id,
                    'title' => $decision->meetingRequest->meeting->title,
                    'scheduled_at' => $decision->meetingRequest->meeting->scheduled_at?->toIso8601String(),
                ] : null,
            ])
            ->values()
            ->all();
    }
}
