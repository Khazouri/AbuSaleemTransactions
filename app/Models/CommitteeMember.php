<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One user's seat on one committee. */
class CommitteeMember extends Model
{
    /**
     * Stage 45 — [D] Art. 10's fixed 5-seat institutional roster. Optional:
     * a membership row with no `seat` is a plain, unstructured member (the
     * open R03/R04 headcount most committees in this system still use).
     */
    public const SEATS = ['chair', 'legal', 'hr_director', 'ministry_delegate', 'rapporteur'];

    protected $fillable = [
        'committee_id',
        'user_id',
        'is_head',
        'seat',
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
