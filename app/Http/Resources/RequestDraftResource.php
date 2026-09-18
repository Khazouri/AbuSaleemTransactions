<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stage 88 — an intake the employee can pick back up.
 *
 * Carries the payload back exactly as it was saved, so resuming restores what
 * was typed rather than a server-side reinterpretation of it. There is no
 * status, reference, receipt or stage here because a draft has none — this
 * resource describing one would be the first step towards a draft looking
 * like a request.
 */
class RequestDraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payload' => $this->payload ?? [],
            // Present only where the caller asked for one draft; the resume
            // list needs a count, not every row.
            'attachments' => RequestDraftAttachmentResource::collection(
                $this->whenLoaded('attachments'),
            ),
            'attachments_count' => $this->whenCounted('attachments'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
