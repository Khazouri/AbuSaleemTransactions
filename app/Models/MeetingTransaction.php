<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One agenda slot, in order — either an `employee_request` item riding an
 * existing transaction, or (Stage 31) a standalone `administrative`/
 * `emerging` item with its own subject/department.
 *
 * @property string $item_type employee_request|administrative|emerging
 * @property string|null $priority high|medium|low
 */
class MeetingTransaction extends Model
{
    // Mirrors the DB column default: create() only sends the attributes it's
    // given, so without this a freshly created request item's in-memory
    // item_type would read null (not 'employee_request') until refetched.
    protected $attributes = [
        'item_type' => 'employee_request',
    ];

    protected $fillable = [
        'meeting_id',
        'transaction_id',
        'agenda_order',
        'item_type',
        'priority',
        'estimated_minutes',
        'subject',
        'department_id',
    ];

    protected function casts(): array
    {
        return [
            'estimated_minutes' => 'integer',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** Stage 31 — only set on an admin item; a request item's department is its transaction's. */
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
}
