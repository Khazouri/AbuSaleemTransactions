<?php

namespace App\Http\Resources;

use App\Models\MeetingTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The committee's binding, tallied outcome for an agenda item. */
class DecisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'outcome' => $this->outcome,
            'votes_approve_count' => $this->votes_approve_count,
            'votes_reject_count' => $this->votes_reject_count,
            'votes_defer_count' => $this->votes_defer_count,
            'comment' => $this->comment,
            'decided_at' => $this->decided_at,
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy ? [
                'id' => $this->decidedBy->id,
                'name' => $this->decidedBy->name,
            ] : null),
            // Stage 25 — the register lists decisions away from the meeting
            // that produced them, so it needs enough context to link back.
            // Loaded only there: the in-meeting UI already knows which meeting
            // and transaction it is looking at, and omitting the key keeps that
            // payload the shape Stage 21 built.
            'context' => $this->whenLoaded(
                'meetingTransaction',
                fn () => $this->contextFor($this->meetingTransaction),
            ),
        ];
    }

    /** @return array<string, mixed>|null */
    private function contextFor(?MeetingTransaction $agendaItem): ?array
    {
        if ($agendaItem === null) {
            return null;
        }

        $meeting = $agendaItem->relationLoaded('meeting') ? $agendaItem->meeting : null;
        $transaction = $agendaItem->relationLoaded('transaction') ? $agendaItem->transaction : null;
        $committee = $meeting?->relationLoaded('committee') ? $meeting->committee : null;

        return [
            'agenda_item_id' => $agendaItem->id,
            'transaction' => $transaction === null ? null : [
                'id' => $transaction->id,
                'reference_number' => $transaction->reference_number,
                'title' => $transaction->title,
            ],
            'meeting' => $meeting === null ? null : [
                'id' => $meeting->id,
                'title' => $meeting->title,
                'scheduled_at' => $meeting->scheduled_at,
            ],
            'committee' => $committee === null ? null : [
                'id' => $committee->id,
                'name_ar' => $committee->name_ar,
                'name_en' => $committee->name_en,
            ],
        ];
    }
}
