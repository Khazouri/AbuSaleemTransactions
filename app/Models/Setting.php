<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A configurable application value, addressed by a stable key. */
class Setting extends Model
{
    /**
     * R08's Settings checkbox (user decision 2026-10-16): while '1', a legal
     * verdict no longer pulls a file off the committee's pending list and the
     * agenda takes a request with no permitting review.
     */
    public const LEGAL_REVIEW_BYPASS = 'legal_review_bypass';

    /**
     * R08's Settings number (user decision 2026-10-16): how many attendees must
     * accept a proposed date before the meeting is `scheduled`. The مقرر's own
     * automatic yes counts, so the floor is 2.
     */
    public const MEETING_ACCEPTANCES_REQUIRED = 'meeting_acceptances_required';

    protected $fillable = [
        'key',
        'value',
    ];

    /** A checkbox setting: on only when stored as '1', so a missing row reads as off. */
    public static function enabled(string $key): bool
    {
        return static::where('key', $key)->value('value') === '1';
    }

    public static function meetingAcceptancesRequired(): int
    {
        return (int) (static::where('key', self::MEETING_ACCEPTANCES_REQUIRED)->value('value') ?? 3);
    }

    /** The `value` rules for a setting the code reads as a typed value; any other key stays free text. */
    public static function valueRules(?string $key): array
    {
        return $key === self::MEETING_ACCEPTANCES_REQUIRED
            ? ['required', 'integer', 'between:2,'.count(CommitteeMember::SEAT_ROLES)]
            : ['nullable', 'string'];
    }
}
