<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Workflow designer — slice 1. One version of a workflow's stages and rules.
 *
 * Nothing at runtime reads `status`: a retired version keeps running every
 * request whose current stage belongs to it. Status only decides which
 * version intake files new requests onto, and which one may still be edited.
 */
class WorkflowVersion extends Model
{
    protected $fillable = [
        'workflow_id',
        'number',
        'status',
        'cloned_from_version_id',
        'published_at',
        'published_by_user_id',
    ];

    /** @var Collection<string, WorkflowStage>|null */
    private ?Collection $stagesByCode = null;

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /**
     * The version the seeders maintain. Seeded fixes land here and nowhere
     * else, so a version an admin published is never rewritten by a re-seed.
     * Created as published only on a database that has none, and an existing
     * one's status is left alone, so a re-seed never un-publishes an admin's.
     */
    public static function seededStandard(): self
    {
        $workflow = Workflow::query()->firstOrCreate(
            ['code' => 'standard'],
            ['name_ar' => 'المسار القياسي', 'name_en' => 'Standard'],
        );

        return self::query()->firstOrCreate(
            ['workflow_id' => $workflow->id, 'number' => 1],
            ['status' => 'published', 'published_at' => now()],
        );
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(WorkflowStage::class);
    }

    /** A rule belongs to the version of the stage it leaves. */
    public function transitions(): HasManyThrough
    {
        return $this->hasManyThrough(WorkflowTransition::class, WorkflowStage::class, 'workflow_version_id', 'from_stage_id');
    }

    /**
     * The stage with this code in THIS version. Throws rather than returning
     * null: every caller is code that names a stage it depends on, and a
     * missing one is a configuration fault, not a branch to handle.
     */
    public function stage(string $code): WorkflowStage
    {
        $this->stagesByCode ??= $this->stages()->get()->keyBy('code');

        return $this->stagesByCode->get($code)
            ?? throw (new ModelNotFoundException)->setModel(WorkflowStage::class, [$code]);
    }
}
