<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WorkflowStage (مرحلة) — one of the 12 steps a request passes through.
 *
 * Order runs 1 (استلام الطلب من البلدية) to 12 (الاعتماد النهائي والأرشفة);
 * see the migration for the full list. Stages 2–4 (direct manager review,
 * administrative routing, receive & register) were added by the
 * diagram-alignment redesign — see AGENT_NOTES.md. Stage 57 later removed two
 * stages (ministry_endorsement, competent_authority) that had no counterpart
 * in the [A]/[D]/[E] standard — 14 stages became 12.
 *
 * @property int $order_no 1..12, unique within its version
 * @property int|null $workflow_version_id
 * @property string $code e.g. 'requirements_check'
 * @property string $name_ar
 * @property int|null $responsible_role_id Display only — NOT access control
 * @property int|null $target_days_min Stage 52 — non-binding soft-SLA target
 * @property int|null $target_days_max Stage 52 — non-binding soft-SLA target
 * @property list<array<string, mixed>>|null $checklist
 */
class WorkflowStage extends Model
{
    protected $fillable = [
        'workflow_version_id',
        'order_no',
        'code',
        'name_ar',
        'name_en',
        'responsible_role_id',
        'description',
        'target_days_min',
        'target_days_max',
        'checklist',
    ];

    /** Workflow designer — slice 4. The kinds of item a step's checklist holds (spec §3). */
    public const CHECK_TYPES = ['yes_no', 'attachment', 'note', 'attestation'];

    /**
     * Workflow designer — slice 5. The stages the engine looks up by code —
     * intake files onto the first, approval returns and suspensions send
     * files back to the committee, both approving authorities land on the
     * last — so no version may be without them (spec §5). 2026-10-17 — the
     * minister's stage joins them: a file the chair gives to الوزير is sent
     * there by code.
     */
    public const ANCHORS = ['receive_from_municipality', 'receive_from_committee', 'local_governance_ministry', 'final_approval_archiving'];

    protected function casts(): array
    {
        return ['checklist' => 'array'];
    }

    /**
     * The role that typically owns this stage, for showing "currently with:"
     * in the UI.
     *
     * Do NOT authorise against this. Permission to act comes from the matching
     * WorkflowTransition's required_role_id.
     */
    public function responsibleRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'responsible_role_id');
    }

    /** Workflow designer — slice 3. A step an admin added; see WorkflowTransition::CUSTOM_PREFIX. */
    public function isCustom(): bool
    {
        return str_starts_with((string) $this->code, WorkflowTransition::CUSTOM_PREFIX);
    }

    /** Workflow designer — slice 5. See ANCHORS. */
    public function isAnchor(): bool
    {
        return in_array($this->code, self::ANCHORS, true);
    }

    /** Workflow designer — slice 1. The version this stage belongs to. */
    public function workflowVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class);
    }

    /**
     * Moves that can be made FROM this stage — i.e. the buttons a user may see
     * on a request sitting here.
     */
    public function outgoingTransitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'from_stage_id');
    }

    /** Moves that land ON this stage. Useful for tracing how work arrives. */
    public function incomingTransitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'to_stage_id');
    }
}
