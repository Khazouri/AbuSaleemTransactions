<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One agenda slot, in order — either an `employee_request` item riding an
 * existing request, a `appeal` item riding an appeal (Stage 63), or a
 * standalone `administrative`/`emerging` item with its own subject/
 * department (Stage 31).
 *
 * @property string $item_type employee_request|administrative|emerging|appeal
 * @property string|null $priority Stage 82 — Appendix 24's two levels: high|normal
 * @property string|null $priority_reason Appendix 24's "مبرر إداري موثق" for a declared high priority
 * @property string $item_state presented|complete (the paper results set `complete`)
 */
class MeetingRequest extends Model
{
    // Mirrors the DB column default: create() only sends the attributes it's
    // given, so without this a freshly created request item's in-memory
    // item_type would read null (not 'employee_request') until refetched.
    protected $attributes = [
        'item_type' => 'employee_request',
        'item_state' => 'presented',
    ];

    protected $fillable = [
        'meeting_id',
        'request_id',
        'appeal_id',
        'agenda_order',
        'item_type',
        'priority',
        // Stage 83 — [D] Appendix 33's five enumerated grounds for عاجل,
        // structuring the free-text مبرر beside it rather than replacing it:
        // the appendix restricts when the description may be granted *and*
        // requires the reason itself to be recorded.
        'priority_reason_code',
        'priority_reason',
        'estimated_minutes',
        'subject',
        'department_id',
        'item_state',
        'state_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'estimated_minutes' => 'integer',
            'state_changed_at' => 'datetime',
        ];
    }

    /**
     * Stage 34 — the one place "is this agenda item done?" is decided. An
     * item reaches it through the paper results, which record its decision
     * and mark it `complete` together (MeetingResultsController).
     */
    public function isResolved(): bool
    {
        if ($this->item_state === 'complete') {
            return true;
        }

        return $this->relationLoaded('decision') ? $this->decision !== null : $this->decision()->exists();
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    /** Stage 63 — set only on an `appeal` item; the appeal riding this agenda slot. */
    public function appeal(): BelongsTo
    {
        return $this->belongsTo(Appeal::class);
    }

    /** Stage 31 — only set on an admin item; a request item's department is its request's. */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** The committee's outcome, once the مقرر has entered the results. */
    public function decision(): HasOne
    {
        return $this->hasOne(Decision::class);
    }
}
