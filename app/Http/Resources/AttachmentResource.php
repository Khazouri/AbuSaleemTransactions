<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Safe metadata returned after Stage 12 stores a private attachment. */
class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->request_id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'label' => $this->label,
            // Stage 80 — [D] Appendix 14's هيكل الملف الإلكتروني. Null on a
            // row written before that classification existed, which the SPA
            // renders as غير مصنف rather than inventing a folder for it.
            'file_section' => $this->file_section,
            // Stage 91 — which of [D] Appendix 57's rows this document
            // answers. Null on a row written before the question was asked,
            // and `other` is a real answer rather than an absence: the
            // matrix names no row for it.
            'required_document_key' => $this->required_document_key,
            // Stage 76 — non-null marks this document as Appendix 70's
            // دليل التنفيذ and records which of its kinds it is.
            'execution_evidence_type' => $this->execution_evidence_type,
            // The client fetches this private stream through its bearer-aware
            // API instance; storage paths themselves never leave the server.
            'preview_url' => route('requests.attachments.preview', [
                'requestRecord' => $this->request_id,
                'attachment' => $this->id,
            ]),
            'uploaded_by_user_id' => $this->uploaded_by_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
