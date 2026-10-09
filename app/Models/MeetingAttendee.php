<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One invited attendee of one meeting, whether they RSVP'd, and whether they showed up. */
class MeetingAttendee extends Model
{
    protected $fillable = [
        'meeting_id',
        'user_id',
        'invitation_status',
        'responded_at',
        'attended',
    ];

    protected function casts(): array
    {
        return [
            'attended' => 'boolean',
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
