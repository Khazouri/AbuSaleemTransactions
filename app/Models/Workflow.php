<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Workflow designer — slice 1. A named workflow, shared by the request types
 * that use it. `standard` is seeded and is what intake uses until request
 * types can pick another (slice 3).
 */
class Workflow extends Model
{
    protected $fillable = ['code', 'name_ar', 'name_en'];

    public static function standard(): self
    {
        return self::query()->where('code', 'standard')->firstOrFail();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    /** The version new requests are filed onto. At most one per workflow. */
    public function publishedVersion(): HasOne
    {
        return $this->hasOne(WorkflowVersion::class)->where('status', 'published');
    }
}
