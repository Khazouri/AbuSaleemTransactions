<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stage 36 — one meeting's compiled minutes and their approval lifecycle:
 * draft → pending_signatures → approved. See MeetingMinutesController for
 * the three actions that move a row between these statuses and
 * MeetingMinutesCompiler for what `content` holds.
 *
 * @property array<string, mixed>|null $content
 * @property string $status draft|pending_signatures|approved
 */
class MeetingMinutes extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_SIGNATURES = 'pending_signatures';

    public const STATUS_APPROVED = 'approved';

    protected $table = 'meeting_minutes';

    protected $fillable = [
        'meeting_id',
        'minutes_number',
        'content',
        'status',
        'generated_by_user_id',
        'generated_at',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_comment',
        // Stage 78 — [D] Appendix 8's sixteen ضوابط جودة المحضر, answered
        // at the moment the head reviews the draft.
        'quality_checks',
        'approved_at',
        // Stage 99 — Appendix 6 row 11, العضو القانوني «مراجعة عند الحاجة».
        'legal_review_note',
        'legal_reviewed_by_user_id',
        'legal_reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'quality_checks' => 'array',
            'generated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'legal_reviewed_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function legalReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'legal_reviewed_by_user_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(MeetingMinuteSignature::class);
    }

    /** True once every required signer has a signed_at — the auto-approve condition. */
    public function allSigned(): bool
    {
        $signatures = $this->relationLoaded('signatures') ? $this->signatures : $this->signatures()->get();

        return $signatures->isNotEmpty() && $signatures->every(fn (MeetingMinuteSignature $signature) => $signature->signed_at !== null);
    }
}
