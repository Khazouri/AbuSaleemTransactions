<?php

namespace App\Models;

use App\Services\Tasks\MeetingDuties;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * جهة الاعتماد's paper قرار (user decision 2026-10-25): its اشاري, its date and
 * its scanned pages, in order. One row covers every file decided in the same
 * submit (MeetingResultsController::recordAuthorityDecisions()).
 *
 * @property list<string> $pages
 */
class AuthorityDecision extends Model
{
    protected $fillable = ['reference', 'decided_on', 'pages', 'decided_by_user_id'];

    protected function casts(): array
    {
        return [
            'decided_on' => 'date',
            'pages' => 'array',
        ];
    }

    /**
     * The قرار the request page shows: the latest one that decided the file.
     * None while the file is back before the authority after the مقرر's
     * return, since that قرار no longer stands and a new one is awaited.
     */
    public static function shownOn(Request $requestRecord): ?self
    {
        if (array_key_exists((string) $requestRecord->currentStage?->code, MeetingDuties::AUTHORITY_STAGES)) {
            return null;
        }

        return self::query()
            ->whereHas('agendaItems', fn ($query) => $query->where('request_id', $requestRecord->id))
            ->latest('id')
            ->first();
    }

    public function agendaItems(): HasMany
    {
        return $this->hasMany(MeetingRequest::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
