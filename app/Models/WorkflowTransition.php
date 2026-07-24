<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WorkflowTransition — one legal move in the state machine.
 *
 * Read a row as:
 *   "A transaction at {fromStage}, when {action} is performed by {requiredRole},
 *    moves to {toStage} and takes status {setStatus}."
 *
 * WorkflowService::transition() (Stage 14) matches the current stage, the
 * requested action and the actor's roles against this table. If no row
 * matches, the move is refused — which means the rules are entirely data, and
 * adding a path is an INSERT rather than a code change.
 *
 * @property string   $action            approve, reject, return_missing_docs...
 * @property bool     $is_exception      Exception path vs normal progress
 * @property bool     $requires_comment  Force the actor to give a reason
 */
class WorkflowTransition extends Model
{
    protected $fillable = [
        'transaction_type_id',
        'from_stage_id',
        'to_stage_id',
        'action',
        'required_role_id',
        'set_status_id',
        'is_exception',
        'requires_comment',
        'order_no',
    ];

    protected function casts(): array
    {
        return [
            'is_exception' => 'boolean',
            'requires_comment' => 'boolean',
        ];
    }

    /**
     * Limits this rule to a single transaction type.
     * Null means the rule applies to every type.
     */
    public function transactionType(): BelongsTo
    {
        return $this->belongsTo(TransactionType::class);
    }

    /** Stage the transaction must currently be at for this rule to fire. */
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
     * check for moving a transaction through the workflow.
     */
    public function requiredRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'required_role_id');
    }

    /** Status stamped on the transaction when the move succeeds. */
    public function setStatus(): BelongsTo
    {
        return $this->belongsTo(TransactionStatus::class, 'set_status_id');
    }
}
