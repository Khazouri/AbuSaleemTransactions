<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One committee member's vote (approve|reject|defer) on one agenda item. */
class Vote extends Model
{
    protected $fillable = [
        'meeting_transaction_id',
        'user_id',
        'vote',
        'comment',
        'voted_at',
    ];

    protected function casts(): array
    {
        return [
            'voted_at' => 'datetime',
        ];
    }

    public function meetingTransaction(): BelongsTo
    {
        return $this->belongsTo(MeetingTransaction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
