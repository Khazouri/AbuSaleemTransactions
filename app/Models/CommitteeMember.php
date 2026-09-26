<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One user's seat on one committee. */
class CommitteeMember extends Model
{
    /**
     * Stage 45 — [D] Art. 10's fixed 5-seat institutional roster. A row with
     * no `seat` can still exist (CommitteeController::store() seats the
     * committee's creator that way, for visibility), but since Stage 102 it is
     * never invited to a meeting and so never votes.
     */
    public const SEATS = ['chair', 'legal', 'hr_director', 'ministry_delegate', 'rapporteur'];

    /**
     * Stage 102 — each seat is held by the role that carries its duties, so a
     * seat and a role can never name two different people (Stage 99 did this
     * for `legal` alone). رئيس اللجنة (وكيل ديوان البلدية) holds R03, whose
     * grants are the chair's; مندوب الخدمة المدنية sits as a voting member
     * (R04), which Art. 14 (أ) says is not a ministry approval — that stays R06.
     * Every seat is required: a meeting is scheduled only once all five are
     * filled (MeetingController::store), and only seated members are invited.
     */
    public const SEAT_ROLES = [
        'chair' => 'R03',
        'legal' => 'R11',
        'hr_director' => 'R12',
        'ministry_delegate' => 'R04',
        'rapporteur' => 'R02',
    ];

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
