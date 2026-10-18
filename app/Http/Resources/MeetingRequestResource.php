<?php

namespace App\Http\Resources;

use App\Services\Lifecycle\UrgencyRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One agenda slot, with just enough of its request to render the agenda
 * list, plus (Stage 21) its votes and, once recorded, its binding decision.
 */
class MeetingRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agenda_order' => $this->agenda_order,
            // Stage 31 — item_type/priority/estimated_minutes apply to every
            // item; subject/department are the admin-item's own, since it has
            // no request to read them from.
            'item_type' => $this->item_type,
            // Stage 82 — Appendix 24's two levels (high/normal) and, when the
            // rapporteur declared one that nothing derived supports, the
            // appendix's own required "مبرر إداري موثق".
            'priority' => $this->priority,
            // Stage 83 — Appendix 33's ground, and its own Arabic wording so
            // the screen and an exported agenda name it identically.
            'priority_reason_code' => $this->priority_reason_code,
            'priority_reason_label' => app(UrgencyRules::class)->label($this->priority_reason_code),
            'priority_reason' => $this->priority_reason,
            'estimated_minutes' => $this->estimated_minutes,
            'subject' => $this->subject,
            // Stage 34 — is_resolved is MeetingRequest::isResolved(), the one
            // place "is this item done?" is decided.
            'item_state' => $this->item_state,
            'state_changed_at' => $this->state_changed_at?->toIso8601String(),
            'is_resolved' => $this->isResolved(),
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name_ar' => $this->department->name_ar,
                'name_en' => $this->department->name_en,
            ] : null),
            'request' => $this->whenLoaded('request', fn () => $this->request ? [
                'id' => $this->request->id,
                'reference_number' => $this->request->reference_number,
                'title' => $this->request->title,
                'status' => $this->request->status ? [
                    'code' => $this->request->status->code,
                    'name_ar' => $this->request->status->name_ar,
                    'name_en' => $this->request->status->name_en,
                    'color' => $this->request->status->color,
                ] : null,
                // 2026-10-18 — what the printed agenda and the paper results
                // need: whose file it is, its type, the grade that suggests
                // الوزير, and where جهة الاعتماد stands on it.
                'approving_authority' => $this->request->approving_authority,
                'suggested_authority' => $this->request->relationLoaded('requestType')
                    ? ($this->request->requiresMinistryApproval() ? 'minister' : 'dean')
                    : null,
                'stage_code' => $this->request->relationLoaded('currentStage') ? $this->request->currentStage?->code : null,
                'subject_name' => $this->request->relationLoaded('subject') ? $this->request->subject?->name : null,
                'request_type' => $this->request->relationLoaded('requestType') && $this->request->requestType ? [
                    'name_ar' => $this->request->requestType->name_ar,
                    'name_en' => $this->request->requestType->name_en,
                ] : null,
            ] : null),
            // Stage 63 — the appeal riding an `appeal` item, mirroring the
            // `request` block above.
            'appeal' => $this->whenLoaded('appeal', fn () => $this->appeal ? [
                'id' => $this->appeal->id,
                'appellant' => $this->appeal->appellant ? [
                    'id' => $this->appeal->appellant->id,
                    'name' => $this->appeal->appellant->name,
                ] : null,
                'original_request' => $this->appeal->originalRequest ? [
                    'id' => $this->appeal->originalRequest->id,
                    'reference_number' => $this->appeal->originalRequest->reference_number,
                    'title' => $this->appeal->originalRequest->title,
                ] : null,
                'status' => $this->appeal->status ? [
                    'code' => $this->appeal->status->code,
                    'name_ar' => $this->appeal->status->name_ar,
                    'name_en' => $this->appeal->status->name_en,
                    'color' => $this->appeal->status->color,
                ] : null,
            ] : null),
            'decision' => $this->whenLoaded('decision', fn () => $this->decision ? new DecisionResource($this->decision) : null),
            // An item read away from its meeting names it; omitted inside the
            // meeting screen, which already knows.
            'meeting' => $this->whenLoaded('meeting', fn () => $this->meetingContext()),
        ];
    }

    /** @return array<string, mixed>|null */
    private function meetingContext(): ?array
    {
        if ($this->meeting === null) {
            return null;
        }

        $committee = $this->meeting->relationLoaded('committee') ? $this->meeting->committee : null;

        return [
            'id' => $this->meeting->id,
            'title' => $this->meeting->title,
            'scheduled_at' => $this->meeting->scheduled_at,
            'committee' => $committee === null ? null : [
                'id' => $committee->id,
                'name_ar' => $committee->name_ar,
                'name_en' => $committee->name_en,
            ],
        ];
    }
}
