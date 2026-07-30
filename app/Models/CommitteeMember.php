<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One user's seat on one committee. */
class CommitteeMember extends Model
{
    protected $fillable = [
        'committee_id',
        'user_id',
        'is_head',
    ];

    protected function casts(): array
    {
        return [
            'is_head' => 'boolean',
        ];
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(Committee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
