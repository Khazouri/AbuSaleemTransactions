<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stage 48 — one member's disclosed conflict of interest on one agenda item.
 * Its existence IS the recusal: DecisionEligibility reads this table to block
 * both voting and joining the discussion feed for the declaring user on this
 * item, per [D] Art. 11/15/18.
 */
class ConflictOfInterestDeclaration extends Model
{
    protected $fillable = [
        'meeting_request_id',
        'user_id',
        'reason',
        'declared_at',
    ];

    protected function casts(): array
    {
        return [
            'declared_at' => 'datetime',
        ];
    }

    public function meetingRequest(): BelongsTo
    {
        return $this->belongsTo(MeetingRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
