<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One agenda slot: a transaction placed on a meeting's table, in order. */
class MeetingTransaction extends Model
{
    protected $fillable = [
        'meeting_id',
        'transaction_id',
        'agenda_order',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
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
