<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Appeal (تظلم) — Stage 58, Track J: a contest against an already-decided
 * Request, never a Request itself and never routed through WorkflowService.
 * See STAGE_PLAN.md Track J's intro for the two scope decisions this rests
 * on (a new entity, not a RequestType; its own status machine, not the
 * 14-stage workflow_stages table).
 *
 * @property int|null $appellant_user_id
 * @property int $original_request_id
 * @property int|null $original_decision_id
 * @property string|null $original_decision_reference Free-text fallback for
 *                                                    a decided matter that has no `decisions` row (e.g.
 *                                                    Stage 54's reject_formally at requirements_check).
 * @property Carbon|null $original_decision_date
 * @property Carbon|null $known_at Stage 59 — تاريخ العلم به.
 * @property string|null $appeal_reasons Stage 59 — أسباب الاعتراض.
 * @property string|null $final_request Stage 59 — الطلب النهائي.
 * @property string|null $new_facts_declaration Stage 59 — [A] §9 step 2's
 *                                              non-duplication escape hatch; see AppealEligibility.
 * @property int|null $appeal_status_id
 * @property array|null $formal_verification_checks Stage 60 — {appellant_standing, valid_target_decision,
 *                                                  deadline_met (bool|null), non_duplication}.
 * @property string|null $formal_verification_reason Required when the verdict above is a fail.
 * @property int|null $formal_verified_by_user_id
 * @property Carbon|null $formal_verified_at
 * @property array|null $jurisdiction_test Stage 62 — {competent_body}, one of
 *                                         committee|mayor|ministry|other_body|disciplinary_or_court.
 * @property int|null $jurisdiction_tested_by_user_id
 * @property Carbon|null $jurisdiction_tested_at
 * @property array|null $legal_review Stage 62 — Art. 75 point 4's 5-question checklist, all booleans.
 * @property int|null $legal_reviewed_by_user_id
 * @property Carbon|null $legal_reviewed_at
 */
class Appeal extends Model
{
    protected $fillable = [
        'appellant_user_id',
        'original_request_id',
        'original_decision_id',
        'original_decision_reference',
        'original_decision_date',
        'known_at',
        'appeal_reasons',
        'final_request',
        'new_facts_declaration',
        'appeal_status_id',
        'formal_verification_checks',
        'formal_verification_reason',
        'formal_verified_by_user_id',
        'formal_verified_at',
        'jurisdiction_test',
        'jurisdiction_tested_by_user_id',
        'jurisdiction_tested_at',
        'legal_review',
        'legal_reviewed_by_user_id',
        'legal_reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'original_decision_date' => 'date',
            'known_at' => 'date',
            'formal_verification_checks' => 'array',
            'formal_verified_at' => 'datetime',
            'jurisdiction_test' => 'array',
            'jurisdiction_tested_at' => 'datetime',
            'legal_review' => 'array',
            'legal_reviewed_at' => 'datetime',
        ];
    }

    public function appellant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appellant_user_id');
    }

    public function originalRequest(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'original_request_id');
    }

    public function originalDecision(): BelongsTo
    {
        return $this->belongsTo(Decision::class, 'original_decision_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(AppealStatus::class, 'appeal_status_id');
    }

    public function formalVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'formal_verified_by_user_id');
    }

    public function jurisdictionTestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'jurisdiction_tested_by_user_id');
    }

    public function legalReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'legal_reviewed_by_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AppealAttachment::class);
    }

    /**
     * May this actor see this appeal? Owner, R08, or anyone holding this
     * screen's `edit` grant — the exact predicate AppealController::index()
     * already applies as a query condition (Stage 60's visibility widening,
     * so an R02 verifier can find appeals filed by other people), now
     * available as a per-instance check for endpoints that load one appeal
     * directly rather than filtering a list.
     */
    public function isVisibleTo(User $actor): bool
    {
        if ($this->appellant_user_id === $actor->id) {
            return true;
        }

        return $actor->roles()->where('code', 'R08')->exists()
            || $actor->hasScreenPermission('appeals', 'can_edit');
    }

    /**
     * Track J intro, scope decision (3): [D] Arts. 34–37's closure rule keeps
     * a matter open until every تظلم path against it has concluded. "Open"
     * here means anything short of the terminal `notified_closed` status
     * (Stage 65 owns setting that) — a null appeal_status_id is treated as
     * open too, defensively, even though store() always sets `submitted`.
     */
    public static function openAgainst(int $requestId): bool
    {
        return static::query()
            ->where('original_request_id', $requestId)
            ->whereDoesntHave('status', fn ($query) => $query->where('code', 'notified_closed'))
            ->exists();
    }
}
