<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stage 88 — an intake the employee has started but not sent.
 *
 * NOT a request, and nothing here should tempt a later reader into making it
 * one: a draft holds no reference number, no receipt, no status, no stage and
 * no history, so it cannot reach a workflow queue, a visibility scope or a
 * register. Those four facts are the stage's own load-bearing rule, and they
 * are true here because this is a different table, not because anything
 * filters it out.
 *
 * The payload is the intake form's fields exactly as typed — deliberately not
 * validated into shape, because a draft is incomplete by definition and
 * requiring a title would defeat the thing. `RequestController::store()` still
 * receives every field in its own payload at submission and validates it
 * there; the draft supplies only the FILES. One source of truth for what is
 * being submitted, and no way for a stale draft to submit something the
 * employee did not just look at on the review screen.
 *
 * Deliberately absent from AuditLog::AUDITED_MODELS: an autosaved half-filled
 * form is not a business event, and a row per keystroke-batch would bury the
 * activity that trail exists for — the same call Stage 23 made for
 * NotificationSetting and Stage 68 for RequestLegalReview.
 */
class RequestDraft extends Model
{
    protected $guarded = [];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RequestDraftAttachment::class);
    }

    /** One stored field, or the fallback for a draft that never reached it. */
    public function field(string $key, mixed $default = null): mixed
    {
        return data_get($this->payload, $key, $default);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
