<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Workflow designer — slice 1. A named workflow, shared by the request types
 * that use it. `standard` is seeded; since slice 3 each request type picks
 * one (`request_types.workflow_id`), and intake files onto its published
 * version.
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

    public function requestTypes(): HasMany
    {
        return $this->hasMany(RequestType::class);
    }

    /** The version new requests are filed onto. At most one per workflow. */
    public function publishedVersion(): HasOne
    {
        return $this->hasOne(WorkflowVersion::class)->where('status', 'published');
    }
}
