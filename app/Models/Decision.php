<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The committee's binding, tallied outcome for one agenda item. */
class Decision extends Model
{
    protected $fillable = [
        'meeting_transaction_id',
        'outcome',
        'votes_approve_count',
        'votes_reject_count',
        'votes_defer_count',
        'comment',
        'decided_by_user_id',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function meetingTransaction(): BelongsTo
    {
        return $this->belongsTo(MeetingTransaction::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
