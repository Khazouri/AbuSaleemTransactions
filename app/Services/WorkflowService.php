<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Approval;
use App\Models\Transaction;
use App\Models\TransactionStageLog;
use App\Models\TransactionStatusHistory;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Executes the transition map stored in workflow_transitions.
 *
 * This service is the single write boundary for moving an existing
 * transaction. The database row is locked before the rule is resolved so two
 * actors cannot both advance the same stage and leave contradictory histories.
 */
class WorkflowService
{
    // Stage 23 — every path that moves a transaction comes through this
    // service, so notifying from here means the detail screen, the approval
    // queues and the committee decision all announce a move exactly once.
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    /**
     * Workflow stage code => immutable business approval level.
     *
     * Stage order remains useful for routing, while this level gives reports
     * a stable reviewer-to-final sequence even if stage labels later change.
     */
    private const APPROVAL_LEVELS = [
        'requirements_check' => 1,
        'receive_from_committee' => 2,
        'approval_by_authority' => 3,
        'local_governance_ministry' => 4,
        'competent_authority' => 5,
        'final_approval_archiving' => 6,
    ];

    /**
     * Actions this actor may currently attempt, for rendering the workspace.
     *
     * The transition method repeats every check under a row lock; this is only
     * an ergonomic preview and must never be treated as authorization by itself.
     *
     * @return Collection<int, string>
     */
    public function availableActions(Transaction $transaction, User $actor): Collection
    {
        return $this->availableTransitions($transaction, $actor)
            ->pluck('action')
            ->values();
    }

    /**
     * Full rule metadata for rendering normal and exception actions distinctly.
     *
     * @return Collection<int, WorkflowTransition>
     */
    // Stage 16 — expose exception/comment metadata without weakening execution checks.
    public function availableTransitions(Transaction $transaction, User $actor): Collection
    {
        if (! $transaction->exists
            || ! $actor->exists
            || ! $actor->is_active
            || $transaction->current_stage_id === null
            || $this->hasTerminalStatus($transaction)) {
            return collect();
        }

        $roleIds = $actor->roles()->pluck('roles.id');

        return WorkflowTransition::query()
            ->where('from_stage_id', $transaction->current_stage_id)
            ->where(function ($query) use ($transaction) {
                $query->whereNull('transaction_type_id');

                if ($transaction->transaction_type_id !== null) {
                    $query->orWhere('transaction_type_id', $transaction->transaction_type_id);
                }
            })
            ->orderByRaw('order_no is null')
            ->orderBy('order_no')
            ->orderBy('id')
            ->get()
            ->groupBy('action')
            ->map(function (Collection $rules) use ($roleIds) {
                $specific = $rules->whereNotNull('transaction_type_id');
                $applicable = $specific->isNotEmpty() ? $specific : $rules->whereNull('transaction_type_id');
                $allowed = $applicable
                    ->filter(fn (WorkflowTransition $rule) => $rule->required_role_id === null
                        || $roleIds->contains($rule->required_role_id))
                    ->values();

                // An ambiguous rule is not genuinely available: execution
                // would fail closed, so the preview must not invite the click.
                return $allowed->count() === 1 ? $allowed->first() : null;
            })
            ->filter()
            ->filter(fn (WorkflowTransition $rule) => $rule->action !== 'deadline_expired' || $transaction->isOverdue())
            ->sortBy(fn (WorkflowTransition $rule) => [$rule->order_no === null, $rule->order_no, $rule->id])
            ->values();
    }

    /**
     * Move a transaction through one configured workflow transition.
     *
     * The optional comment is unused by the Stage 14 happy path, but belongs at
     * this boundary because Stage 16 exception rules can require it. Approval
     * transitions also require the private signature path introduced in Stage
     * 19, even when this service is called outside an HTTP controller.
     *
     * @throws WorkflowTransitionException
     */
    // Stage 14 — atomic, role-aware workflow state transitions.
    public function transition(
        Transaction $transaction,
        string $action,
        User $actor,
        ?string $comment = null,
        ?string $signaturePath = null,
    ): Transaction {
        if (! $transaction->exists) {
            throw WorkflowTransitionException::transactionNotPersisted();
        }

        if (! $actor->exists || ! $actor->is_active) {
            throw WorkflowTransitionException::actorNotActive();
        }

        $action = trim($action);
        $comment = filled($comment) ? trim($comment) : null;
        $signaturePath = filled($signaturePath) ? trim($signaturePath) : null;

        if ($action === '') {
            throw WorkflowTransitionException::actionRequired();
        }

        [$movedTransaction, $fromStage, $toStage] = DB::transaction(function () use ($transaction, $action, $actor, $comment, $signaturePath) {
            $lockedTransaction = Transaction::query()
                ->lockForUpdate()
                ->findOrFail($transaction->getKey());

            if ($lockedTransaction->current_stage_id === null) {
                throw WorkflowTransitionException::currentStageRequired();
            }

            if ($this->hasTerminalStatus($lockedTransaction)) {
                throw WorkflowTransitionException::transactionClosed();
            }

            $candidates = $this->transitionCandidates($lockedTransaction, $action);

            if ($candidates->isEmpty()) {
                throw WorkflowTransitionException::transitionNotConfigured();
            }

            $roleIds = $actor->roles()->pluck('roles.id');
            $allowed = $candidates
                ->filter(fn (WorkflowTransition $rule) => $rule->required_role_id === null
                    || $roleIds->contains($rule->required_role_id))
                ->values();

            if ($allowed->isEmpty()) {
                throw WorkflowTransitionException::roleNotAllowed();
            }

            // Multiple matches make the destination depend on row order. Fail
            // closed so a configuration mistake cannot move work unpredictably.
            if ($allowed->count() > 1) {
                throw WorkflowTransitionException::ambiguousConfiguration();
            }

            /** @var WorkflowTransition $rule */
            $rule = $allowed->first();

            if ($rule->action === 'deadline_expired' && ! $lockedTransaction->isOverdue()) {
                throw WorkflowTransitionException::deadlineNotExpired();
            }

            if ($rule->requires_comment && $comment === null) {
                throw WorkflowTransitionException::commentRequired();
            }

            $approvalLevel = $this->approvalLevel($rule);
            if ($approvalLevel !== null && $signaturePath === null) {
                throw WorkflowTransitionException::signatureRequired();
            }

            $fromStageId = $lockedTransaction->current_stage_id;
            $fromStatusId = $lockedTransaction->status_id;
            $toStageId = $this->destinationStageId($lockedTransaction, $rule);

            $lockedTransaction->current_stage_id = $toStageId;
            if ($rule->set_status_id !== null) {
                $lockedTransaction->status_id = $rule->set_status_id;
            }
            $lockedTransaction->save();

            $occurredAt = now();

            TransactionStageLog::create([
                'transaction_id' => $lockedTransaction->id,
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $toStageId,
                'action' => $action,
                'comment' => $comment,
                'acted_by_user_id' => $actor->id,
                'acted_at' => $occurredAt,
            ]);

            if ($approvalLevel !== null) {
                Approval::create([
                    'transaction_id' => $lockedTransaction->id,
                    'level' => $approvalLevel,
                    'role_id' => $rule->required_role_id,
                    'approved_by_user_id' => $actor->id,
                    'action' => $action,
                    'comment' => $comment,
                    'signature_path' => $signaturePath,
                    'approved_at' => $occurredAt,
                ]);
            }

            // A configured status is stamped and recorded on every move, even
            // when adjacent stages share a broad status such as `in_review`.
            // That preserves the exact rule outcome alongside the stage log.
            if ($rule->set_status_id !== null) {
                TransactionStatusHistory::create([
                    'transaction_id' => $lockedTransaction->id,
                    'from_status_id' => $fromStatusId,
                    'to_status_id' => $rule->set_status_id,
                    'reason' => $comment,
                    'changed_by_user_id' => $actor->id,
                    'changed_at' => $occurredAt,
                ]);
            }

            // The stage rows travel out of the closure so the notification can
            // name where the work came from without a second lookup.
            return [
                $lockedTransaction->refresh(),
                WorkflowStage::find($fromStageId),
                WorkflowStage::find($toStageId),
            ];
        });

        // Outside the transaction: the notifications describe a move that has
        // already happened. The dispatcher queues them after-commit as well
        // (see its send()), so a caller that wraps this in a transaction of
        // its own — DecisionController does — still can't announce a decision
        // that later rolls back.
        $this->notifications->stageChanged($movedTransaction, $actor, $action, $fromStage, $toStage);

        return $movedTransaction;
    }

    /**
     * Resolve rules for this type, letting type-specific rows replace generic
     * rows for the same stage/action rather than competing with them.
     *
     * @return Collection<int, WorkflowTransition>
     */
    private function transitionCandidates(Transaction $transaction, string $action): Collection
    {
        $candidates = WorkflowTransition::query()
            ->where('from_stage_id', $transaction->current_stage_id)
            ->where('action', $action)
            ->where(function ($query) use ($transaction) {
                $query->whereNull('transaction_type_id');

                if ($transaction->transaction_type_id !== null) {
                    $query->orWhere('transaction_type_id', $transaction->transaction_type_id);
                }
            })
            ->get();

        $specific = $candidates->whereNotNull('transaction_type_id')->values();

        return $specific->isNotEmpty()
            ? $specific
            : $candidates->whereNull('transaction_type_id')->values();
    }

    /**
     * Stage 18 conditional branch: after the admin manager approves, low-grade
     * work bypasses ministry and lands directly with the competent authority.
     */
    private function destinationStageId(Transaction $transaction, WorkflowTransition $rule): int
    {
        $fromStageCode = $rule->fromStage()->value('code');

        if ($rule->action !== 'approve'
            || $fromStageCode !== 'approval_by_authority'
            || $transaction->requiresMinistryApproval()) {
            return $rule->to_stage_id;
        }

        return WorkflowStage::query()
            ->where('code', 'competent_authority')
            ->firstOrFail()
            ->id;
    }

    /** Only normal `approve` moves belong in the approval-specific ledger. */
    private function approvalLevel(WorkflowTransition $rule): ?int
    {
        if ($rule->action !== 'approve' || $rule->is_exception) {
            return null;
        }

        return self::APPROVAL_LEVELS[$rule->fromStage()->value('code')] ?? null;
    }

    /**
     * These statuses have left the stage-changing workflow even when the last
     * stage still has configured rules. `in_execution` is deliberately here:
     * Stage 37's status-only output service owns its eventual close.
     */
    private function hasTerminalStatus(Transaction $transaction): bool
    {
        return $transaction->status()
            ->whereIn('code', ['cancelled', 'archived', 'in_execution', 'completed_closed'])
            ->exists();
    }
}
