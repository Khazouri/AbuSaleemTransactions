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

    protected $fillable = [
        'key',
        'value',
    ];

    /** A checkbox setting: on only when stored as '1', so a missing row reads as off. */
    public static function enabled(string $key): bool
    {
        return static::where('key', $key)->value('value') === '1';
    }
}
