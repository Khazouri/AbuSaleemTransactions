<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Safe metadata for a supporting document uploaded against an Appeal — Stage 59. */
class AppealAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appeal_id' => $this->appeal_id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'label' => $this->label,
            'preview_url' => route('appeals.attachments.preview', [
                'appeal' => $this->appeal_id,
                'attachment' => $this->id,
            ]),
            'uploaded_by_user_id' => $this->uploaded_by_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
