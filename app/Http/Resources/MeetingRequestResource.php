<?php

namespace App\Http\Resources;

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
            'priority' => $this->priority,
            'estimated_minutes' => $this->estimated_minutes,
            'subject' => $this->subject,
            // Stage 34 — the runner's own progress tracker; is_resolved is the
            // same predicate MeetingController::update()'s close gate uses, so
            // the progress bar and the gate can never disagree.
            'item_state' => $this->item_state,
            'state_changed_at' => $this->state_changed_at?->toIso8601String(),
            'is_resolved' => $this->isResolved(),
            'notes' => $this->whenLoaded('notes', fn () => MeetingDiscussionNoteResource::collection($this->notes)),
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
            ] : null),
            'votes' => $this->whenLoaded('votes', fn () => VoteResource::collection($this->votes)),
            'decision' => $this->whenLoaded('decision', fn () => $this->decision ? new DecisionResource($this->decision) : null),
            // Stage 25 — the pending-votes worklist shows items from several
            // meetings at once, so each one has to name its own. Omitted inside
            // the meeting screen, which already knows.
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
