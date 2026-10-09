<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stage 34 — one entry in the live runner's discussion feed for an agenda item. */
class MeetingDiscussionNote extends Model
{
    protected $fillable = [
        'meeting_request_id',
        'note',
        'created_by_user_id',
    ];

    public function meetingRequest(): BelongsTo
    {
        return $this->belongsTo(MeetingRequest::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
