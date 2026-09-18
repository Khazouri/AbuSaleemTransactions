<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stage 88 — a file already uploaded against a draft.
 *
 * Carries the same columns `attachments` does because that is what it becomes:
 * at submission RequestController::store() copies the stored file into the new
 * request's own directory and writes an `Attachment` row from these values,
 * deriving [D] Appendix 14's folder from `required_document_key` exactly as an
 * inline intake attachment does.
 *
 * It exists at all because "a refresh loses every chosen file" is half of what
 * Stage 88 is for, and a browser cannot reconstruct a File across one — so a
 * draft's files have to be on the server, which is also what lets the review
 * step show them back.
 */
class RequestDraftAttachment extends Model
{
    protected $guarded = [];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(RequestDraft::class, 'request_draft_id');
    }
}
