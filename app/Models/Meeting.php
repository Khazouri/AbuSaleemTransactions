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
 * @property string $status pending_confirmation|scheduled|completed|cancelled
 *                          Stage 102 — a meeting starts `pending_confirmation` and
 *                          becomes `scheduled` (approved) when every invited member
 *                          accepts its date.
 * @property string|null $meeting_number
 * @property string $meeting_type regular (Stage 102 — the monthly sitting is the only kind;
 *                                extraordinary|emergency survive on older rows)
 * @property Carbon|null $agenda_deadline
 */
class Meeting extends Model
{
    use HasFactory;

    public const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';

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
        // 2026-10-18 — the paper sitting's محضر and who entered its results.
        'minutes_file_path',
        'minutes_file_name',
        'minutes_file_mime',
        // 2026-10-25 — the scanned pages that replaced the one file.
        'minutes_pages',
        'results_recorded_at',
        'results_recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'agenda_deadline' => 'datetime',
            'expected_duration_minutes' => 'integer',
            'results_recorded_at' => 'datetime',
            'minutes_pages' => 'array',
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

    public function resultsRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'results_recorded_by_user_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(MeetingAttendee::class);
    }

    /** The agenda, in display order. */
    public function agendaItems(): HasMany
    {
        return $this->hasMany(MeetingRequest::class)->orderBy('agenda_order');
    }
}
