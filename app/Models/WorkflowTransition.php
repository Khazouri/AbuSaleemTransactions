<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WorkflowTransition — one legal move in the state machine.
 *
 * Read a row as:
 *   "A request at {fromStage}, when {action} is performed by {requiredRole},
 *    moves to {toStage} and takes status {setStatus}."
 *
 * WorkflowService::transition() (Stage 14) matches the current stage, the
 * requested action and the actor's roles against this table. If no row
 * matches, the move is refused — which means the rules are entirely data, and
 * adding a path is an INSERT rather than a code change.
 *
 * @property string $action approve, reject, return_missing_docs...
 * @property bool $is_exception Exception path vs normal progress
 * @property bool $requires_comment Force the actor to give a reason
 * @property bool $requires_checks Workflow designer — slice 4: waits on the from-stage's checklist
 */
class WorkflowTransition extends Model
{
    protected $fillable = [
        'request_type_id',
        'from_stage_id',
        'to_stage_id',
        'action',
        'label_ar',
        'label_en',
        'required_role_id',
        'requires_submitter_manager',
        'requires_creator',
        'required_status_id',
        'set_status_id',
        'is_exception',
        'requires_comment',
        'requires_checks',
        'order_no',
    ];

    /**
     * Workflow designer — slice 3. Outcomes an admin adds carry this prefix
     * on their action, and the server mints it, so the prefix alone tells a
     * built-in rule (locked until slice 5, named by the locale files) from a
     * custom one (editable, named by label_ar/label_en).
     */
    public const CUSTOM_PREFIX = 'custom_';

    public function isCustom(): bool
    {
        return str_starts_with((string) $this->action, self::CUSTOM_PREFIX);
    }

    protected function casts(): array
    {
        return [
            'is_exception' => 'boolean',
            'requires_comment' => 'boolean',
            'requires_checks' => 'boolean',
            'requires_submitter_manager' => 'boolean',
            'requires_creator' => 'boolean',
        ];
    }

    /**
     * Limits this rule to a single request type.
     * Null means the rule applies to every type.
     */
    public function requestType(): BelongsTo
    {
        return $this->belongsTo(RequestType::class);
    }

    /** Stage the request must currently be at for this rule to fire. */
    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'from_stage_id');
    }

    /**
     * Stage it moves to. On exception rows this points at an EARLIER stage —
     * that's how "return for missing documents" is expressed.
     */
    public function toStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'to_stage_id');
    }

    /**
     * The role permitted to perform this action. This is the authoritative
     * check for moving a request through the workflow.
     */
    public function requiredRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'required_role_id');
    }

    /** Status stamped on the request when the move succeeds. */
    public function setStatus(): BelongsTo
    {
        return $this->belongsTo(RequestStatus::class, 'set_status_id');
    }

    /**
     * The request's CURRENT status this rule additionally requires, on
     * top of `required_role_id`. Null means no status restriction. This is
     * what makes three-way administrative routing enforceable: role alone
     * can't distinguish which of several routed-to statuses a file carries.
     */
    public function requiredStatus(): BelongsTo
    {
        return $this->belongsTo(RequestStatus::class, 'required_status_id');
    }
}
