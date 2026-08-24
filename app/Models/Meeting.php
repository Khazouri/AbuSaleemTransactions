<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A scheduled sitting of one committee.
 *
 * @property string $title
 * @property Carbon $scheduled_at
 * @property string|null $location
 * @property string $status scheduled|completed|cancelled
 * @property string|null $minutes
 * @property string|null $meeting_number
 * @property string $meeting_type regular|extraordinary|emergency
 * @property Carbon|null $agenda_deadline
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
        'status',
        'minutes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'agenda_deadline' => 'datetime',
            'expected_duration_minutes' => 'integer',
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

    public function attendees(): HasMany
    {
        return $this->hasMany(MeetingAttendee::class);
    }

    /** The agenda, in display/vote order. */
    public function agendaItems(): HasMany
    {
        return $this->hasMany(MeetingTransaction::class)->orderBy('agenda_order');
    }
}
