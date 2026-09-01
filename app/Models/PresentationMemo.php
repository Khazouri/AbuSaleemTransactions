<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * [D] Art. 22's compiled pre-meeting memo for one agenda item. `content` is
 * `{derived: {...}, authored: {...}}` — see PresentationMemoCompiler for the
 * derived half and PresentationMemoController::update() for the authored one.
 */
class PresentationMemo extends Model
{
    protected $fillable = [
        'meeting_request_id',
        'content',
        'generated_by_user_id',
        'generated_at',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function meetingRequest(): BelongsTo
    {
        return $this->belongsTo(MeetingRequest::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
