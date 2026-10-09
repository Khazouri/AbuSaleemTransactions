<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Stage 88 — a file already uploaded against an unsent intake. */
class RequestDraftAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_draft_id' => $this->request_draft_id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'label' => $this->label,
            // Which [D] Appendix 57 row the employee says this file answers.
            // Null while the draft has no type chosen, or after a type change
            // made every earlier answer meaningless.
            'required_document_key' => $this->required_document_key,
            // [D] Appendix 14's folder is deliberately NOT here: it is derived
            // at submission from the key above, so a draft carrying one would
            // be a second answer to a question nobody asked twice.
            //
            // The stream travels through the bearer-aware API instance like
            // every other private file; storage paths never leave the server.
            'preview_url' => route('requests.drafts.attachments.preview', [
                'draft' => $this->request_draft_id,
                'draftAttachment' => $this->id,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
