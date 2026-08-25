<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stage 36 — one attendee's required, individually-captured signature on a meeting's minutes. */
class MeetingMinuteSignature extends Model
{
    protected $fillable = [
        'meeting_minutes_id',
        'user_id',
        'signature_path',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function minutes(): BelongsTo
    {
        return $this->belongsTo(MeetingMinutes::class, 'meeting_minutes_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
