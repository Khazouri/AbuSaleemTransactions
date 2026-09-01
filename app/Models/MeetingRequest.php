<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One agenda slot, in order — either an `employee_request` item riding an
 * existing request, or (Stage 31) a standalone `administrative`/
 * `emerging` item with its own subject/department.
 *
 * @property string $item_type employee_request|administrative|emerging
 * @property string|null $priority high|medium|low
 * @property string $item_state presented|discussion|voting|deciding|complete
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
        'agenda_order',
        'item_type',
        'priority',
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
     * Stage 34 — the one place "is this agenda item done?" is decided, so the
     * meeting-close gate and the runner's progress readout can't disagree.
     * A request item reaches this only via a recorded decision (see
     * DecisionController::record); an admin/emerging item only via the
     * runner's manual state endpoint, which is the case the vote/decision
     * machinery has no way to resolve on its own.
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

    /** Stage 31 — only set on an admin item; a request item's department is its request's. */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Stage 21 — every committee member's vote cast on this agenda item. */
    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    /** Stage 21 — the binding outcome, once the head has recorded it. */
    public function decision(): HasOne
    {
        return $this->hasOne(Decision::class);
    }

    /** Stage 34 — the live runner's discussion feed, oldest first. */
    public function notes(): HasMany
    {
        return $this->hasMany(MeetingDiscussionNote::class)->orderBy('created_at');
    }

    /** Stage 46 — the compiled pre-meeting memo, [D] Art. 22. */
    public function presentationMemo(): HasOne
    {
        return $this->hasOne(PresentationMemo::class);
    }

    /** Stage 48 — every member who has disclosed a stake in this item. */
    public function conflictDeclarations(): HasMany
    {
        return $this->hasMany(ConflictOfInterestDeclaration::class);
    }
}
