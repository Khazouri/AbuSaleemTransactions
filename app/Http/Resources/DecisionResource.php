<?php

namespace App\Http\Resources;

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
        ];
    }
}
