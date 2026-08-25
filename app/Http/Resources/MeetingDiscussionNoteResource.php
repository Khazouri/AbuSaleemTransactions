<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Stage 34 — one entry in the live runner's discussion feed. */
class MeetingDiscussionNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
        ];
    }
}
