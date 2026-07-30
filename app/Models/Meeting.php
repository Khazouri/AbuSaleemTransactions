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
 */
class Meeting extends Model
{
    use HasFactory;

    protected $fillable = [
        'committee_id',
        'title',
        'scheduled_at',
        'location',
        'status',
        'minutes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
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
