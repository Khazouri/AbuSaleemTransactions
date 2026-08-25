<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One agenda slot, with just enough of its transaction to render the agenda
 * list, plus (Stage 21) its votes and, once recorded, its binding decision.
 */
class MeetingTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agenda_order' => $this->agenda_order,
            // Stage 31 — item_type/priority/estimated_minutes apply to every
            // item; subject/department are the admin-item's own, since it has
            // no transaction to read them from.
            'item_type' => $this->item_type,
            'priority' => $this->priority,
            'estimated_minutes' => $this->estimated_minutes,
            'subject' => $this->subject,
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name_ar' => $this->department->name_ar,
                'name_en' => $this->department->name_en,
            ] : null),
            'transaction' => $this->whenLoaded('transaction', fn () => $this->transaction ? [
                'id' => $this->transaction->id,
                'reference_number' => $this->transaction->reference_number,
                'title' => $this->transaction->title,
                'status' => $this->transaction->status ? [
                    'code' => $this->transaction->status->code,
                    'name_ar' => $this->transaction->status->name_ar,
                    'name_en' => $this->transaction->status->name_en,
                    'color' => $this->transaction->status->color,
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
