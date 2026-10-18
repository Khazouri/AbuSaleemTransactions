<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One invited attendee of one meeting, whether they RSVP'd, and whether they showed up.
 * A row with a `guest_title` is an extra member the مقرر invited beyond the five
 * seats: they answer the date and nothing else.
 */
class MeetingAttendee extends Model
{
    protected $fillable = [
        'meeting_id',
        'user_id',
        'guest_title',
        'invitation_status',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
