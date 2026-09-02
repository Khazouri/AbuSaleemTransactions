<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WorkflowStage (مرحلة) — one of the 14 steps a request passes through.
 *
 * Order runs 1 (استلام الطلب من البلدية) to 14 (الاعتماد النهائي والأرشفة);
 * see the migration for the full list. Stages 2–4 (direct manager review,
 * administrative routing, receive & register) were added by the
 * diagram-alignment redesign — see AGENT_NOTES.md.
 *
 * @property int $order_no 1..14
 * @property string $code e.g. 'requirements_check'
 * @property string $name_ar
 * @property int|null $responsible_role_id Display only — NOT access control
 * @property int|null $target_days_min Stage 52 — non-binding soft-SLA target
 * @property int|null $target_days_max Stage 52 — non-binding soft-SLA target
 */
class WorkflowStage extends Model
{
    protected $fillable = [
        'order_no',
        'code',
        'name_ar',
        'name_en',
        'responsible_role_id',
        'description',
        'target_days_min',
        'target_days_max',
    ];

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
