<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingMinutesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'content' => $this->content,
            'generated_by' => $this->whenLoaded('generatedBy', fn () => $this->generatedBy ? [
                'id' => $this->generatedBy->id,
                'name' => $this->generatedBy->name,
            ] : null),
            'generated_at' => $this->generated_at?->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy ? [
                'id' => $this->reviewedBy->id,
                'name' => $this->reviewedBy->name,
            ] : null),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_comment' => $this->review_comment,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'signatures' => MeetingMinuteSignatureResource::collection($this->whenLoaded('signatures')),
        ];
    }
}
