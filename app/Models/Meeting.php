<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A scheduled sitting of one committee.
 *
 * @property string $title
 * @property Carbon $scheduled_at
 * @property string|null $location
 * @property string $status scheduled|completed|cancelled
 * @property string|null $meeting_number
 * @property string $meeting_type regular|extraordinary|emergency
 * @property Carbon|null $agenda_deadline
 * @property Carbon|null $convened_at
 * @property string|null $readiness_override_reason
 * @property array|null $voting_rules_snapshot Stage 73 — the committee's quorum/majority rules
 *                                             frozen at convene time, so editing the committee
 *                                             card later cannot rewrite a sitting already held.
 */
class Meeting extends Model
{
    use HasFactory;

    protected $fillable = [
        'committee_id',
        'meeting_number',
        'title',
        'meeting_type',
        'scheduled_at',
        'location',
        'chairman_user_id',
        'rapporteur_user_id',
        'expected_duration_minutes',
        'agenda_deadline',
        'description',
        // Stage 82 — Appendix 24's "مبرر إداري موثق" for an agenda that
        // departs from Art. 83's own ordering.
        'agenda_order_justification',
        'status',
        'created_by_user_id',
        'convened_at',
        'convened_by_user_id',
        'readiness_override_reason',
        'voting_rules_snapshot',
        // Stage 99 — Art. 84's «اعتماد جدول الأعمال» (Appendix 6 row 8).
        'agenda_adopted_at',
        'agenda_adopted_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'agenda_deadline' => 'datetime',
            'expected_duration_minutes' => 'integer',
            'convened_at' => 'datetime',
            'agenda_adopted_at' => 'datetime',
            'voting_rules_snapshot' => 'array',
        ];
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(Committee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function chairman(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chairman_user_id');
    }

    public function rapporteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rapporteur_user_id');
    }

    public function convenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'convened_by_user_id');
    }

    public function agendaAdoptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agenda_adopted_by_user_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(MeetingAttendee::class);
    }

    /** The agenda, in display/vote order. */
    public function agendaItems(): HasMany
    {
        return $this->hasMany(MeetingRequest::class)->orderBy('agenda_order');
    }

    /** Stage 36 — the compiled/reviewed/signed minutes document, one per meeting. */
    public function meetingMinutes(): HasOne
    {
        return $this->hasOne(MeetingMinutes::class);
    }
}
