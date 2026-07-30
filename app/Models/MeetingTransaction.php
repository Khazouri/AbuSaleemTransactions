<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
