<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compact request payload for the Stage 11 searchable list. */
class RequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            // Stage 70 — null once the قيد grants a real reference; before that
            // it is the only handle the employee has (see Request::trackingNumber).
            'intake_receipt_number' => $this->intake_receipt_number,
            'title' => $this->title,
            'department' => $this->department ? [
                'id' => $this->department->id,
                'name_ar' => $this->department->name_ar,
                'name_en' => $this->department->name_en,
                'code' => $this->department->code,
            ] : null,
            'request_type' => $this->requestType ? [
                'id' => $this->requestType->id,
                'code' => $this->requestType->code,
                'name_ar' => $this->requestType->name_ar,
                'name_en' => $this->requestType->name_en,
                'decision_grade_threshold' => $this->requestType->decision_grade_threshold,
                // Stage 56 — an advisory suggestion only; all 3
                // administrative_routing actions stay freely selectable.
                'default_administrative_route' => $this->requestType->default_administrative_route,
            ] : null,
            'decision_grade' => $this->decision_grade,
            'requires_ministry_approval' => $this->requiresMinistryApproval(),
            // Stage 47 — derived from request type at intake, correctable
            // afterward via PATCH .../financial-impact.
            'has_financial_impact' => (bool) $this->has_financial_impact,
            'status' => $this->status ? [
                'code' => $this->status->code,
                'name_ar' => $this->status->name_ar,
                'name_en' => $this->status->name_en,
                'color' => $this->status->color,
            ] : null,
            'current_stage' => $this->currentStage ? [
                'order_no' => $this->currentStage->order_no,
                'code' => $this->currentStage->code,
                'name_ar' => $this->currentStage->name_ar,
                'name_en' => $this->currentStage->name_en,
            ] : null,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'due_date' => $this->due_date?->toDateString(),
            'is_overdue' => $this->isOverdue(),
            'overdue_at' => $this->overdue_at?->toIso8601String(),
            // Stage 52 — soft, non-blocking per-stage target; null when the
            // current stage has no sourced target duration.
            'stage_timeliness' => $this->stageTimeliness(),
            'created_at' => $this->created_at?->toIso8601String(),
            // Stage 44 — the requester ("الموظف"), only populated when a
            // caller explicitly eager-loads createdBy (e.g. the committee
            // candidates worklist); every other caller of this shared
            // resource stays exactly as before.
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            // Requires the caller to add ->withCount('attachments') — a
            // stand-in for [C] §2's "اكتمال الملف" column, derived rather
            // than a fabricated field, matching MeetingReadinessService's
            // own "does this item have attachments" file-readiness check.
            'attachments_count' => $this->when(
                array_key_exists('attachments_count', $this->getAttributes()),
                fn () => (int) $this->attachments_count,
            ),
            // The nearest meeting this candidate is already penciled onto,
            // if any — [C] §2's "الاجتماع المقترح" column. Only populated
            // when the caller eager-loads meetingRequests.meeting.
            'proposed_meeting' => $this->whenLoaded('meetingRequests', function () {
                $agendaItem = $this->meetingRequests->sortByDesc('id')->first();
                $meeting = $agendaItem?->meeting;

                return $meeting ? [
                    'id' => $meeting->id,
                    'title' => $meeting->title,
                    'scheduled_at' => $meeting->scheduled_at?->toIso8601String(),
                    'status' => $meeting->status,
                    'priority' => $agendaItem->priority,
                ] : null;
            }),
        ];
    }
}
