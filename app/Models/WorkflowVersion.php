<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;

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

    /** Workflow designer — slice 3. Every write to a version that is no longer a draft is refused with this. */
    public const NOT_A_DRAFT = 'لا يُعدَّل إلا إصدار في حالة مسودة؛ أنشئ مسودة من هذا الإصدار.';

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
     * Workflow designer — slice 5. Every attachment item on this version's
     * checklists, with its step's name, for the Request Types save that must
     * not re-key a document one of them names.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function attachmentItems(): \Illuminate\Support\Collection
    {
        return $this->stages()->whereNotNull('checklist')->get()
            ->flatMap(fn (WorkflowStage $stage) => collect($stage->checklist)->where('type', 'attachment')
                ->map(fn (array $item) => [...$item, 'stage_name_ar' => $stage->name_ar]))
            ->values()->toBase();
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

    /**
     * Workflow designer — slice 1. A draft copy of this version: every stage
     * and rule duplicated onto rows of its own, with each rule's from/to
     * re-pointed at the copied stages, so editing the draft can never touch
     * a request in flight on this one. `$into` makes the copy the first draft
     * of another workflow instead (slice 3's «مسار جديد»), numbered from 1
     * there.
     */
    public function cloneAsDraft(?Workflow $into = null): self
    {
        $workflowId = $into?->id ?? $this->workflow_id;

        return DB::transaction(function () use ($workflowId) {
            $draft = self::create([
                'workflow_id' => $workflowId,
                'number' => (int) self::query()->where('workflow_id', $workflowId)->max('number') + 1,
                'status' => 'draft',
                'cloned_from_version_id' => $this->id,
            ]);

            $stageIds = [];
            foreach ($this->stages()->get() as $stage) {
                $copy = $stage->replicate();
                $copy->workflow_version_id = $draft->id;
                $copy->save();
                $stageIds[$stage->id] = $copy->id;
            }

            foreach ($this->transitions()->get() as $rule) {
                $copy = $rule->replicate(['laravel_through_key']);
                $copy->from_stage_id = $stageIds[$rule->from_stage_id];
                $copy->to_stage_id = $stageIds[$rule->to_stage_id];
                $copy->save();
            }

            return $draft;
        });
    }
}
