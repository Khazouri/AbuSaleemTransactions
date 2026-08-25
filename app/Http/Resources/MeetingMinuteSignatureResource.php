<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingMinuteSignatureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'signed_at' => $this->signed_at?->toIso8601String(),
            // The private path never crosses the API boundary — same pattern
            // ApprovalResource uses for its own signature_url.
            'signature_url' => $this->signature_path
                ? route('meeting-minutes.signature', ['signature' => $this->id])
                : null,
        ];
    }
}
