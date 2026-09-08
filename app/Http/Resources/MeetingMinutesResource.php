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
            // Stage 70 — [D] Appendix 15's PM-MIN series.
            'minutes_number' => $this->minutes_number,
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
            // Stage 78 — Appendix 8's sixteen ضوابط جودة المحضر as answered at
            // review time; null on a draft nobody has reviewed yet.
            'quality_checks' => $this->quality_checks,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'signatures' => MeetingMinuteSignatureResource::collection($this->whenLoaded('signatures')),
        ];
    }
}
