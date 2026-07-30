<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Transaction;
use App\Models\TransactionStageLog;
use App\Models\TransactionStatusHistory;
use App\Models\User;
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
        if (! $transaction->exists || ! $actor->exists || ! $actor->is_active || $transaction->current_stage_id === null) {
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
            ->get()
            ->groupBy('action')
            ->map(function (Collection $rules) use ($roleIds) {
                $specific = $rules->whereNotNull('transaction_type_id');
                $applicable = $specific->isNotEmpty() ? $specific : $rules->whereNull('transaction_type_id');

                return $applicable->contains(fn (WorkflowTransition $rule) => $rule->required_role_id === null
                    || $roleIds->contains($rule->required_role_id));
            })
            ->filter()
            ->keys()
            ->values();
    }

    /**
     * Move a transaction through one configured workflow transition.
     *
     * The optional comment is unused by the Stage 14 happy path, but belongs at
     * this boundary now because Stage 16 exception rules can require it.
     *
     * @throws WorkflowTransitionException
     */
    // Stage 14 — atomic, role-aware workflow state transitions.
    public function transition(
        Transaction $transaction,
        string $action,
        User $actor,
        ?string $comment = null,
    ): Transaction {
        if (! $transaction->exists) {
            throw WorkflowTransitionException::transactionNotPersisted();
        }

        if (! $actor->exists || ! $actor->is_active) {
            throw WorkflowTransitionException::actorNotActive();
        }

        $action = trim($action);
        $comment = filled($comment) ? trim($comment) : null;

        if ($action === '') {
            throw WorkflowTransitionException::actionRequired();
        }

        return DB::transaction(function () use ($transaction, $action, $actor, $comment) {
            $lockedTransaction = Transaction::query()
                ->lockForUpdate()
                ->findOrFail($transaction->getKey());

            if ($lockedTransaction->current_stage_id === null) {
                throw WorkflowTransitionException::currentStageRequired();
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

            if ($rule->requires_comment && $comment === null) {
                throw WorkflowTransitionException::commentRequired();
            }

            $fromStageId = $lockedTransaction->current_stage_id;
            $fromStatusId = $lockedTransaction->status_id;

            $lockedTransaction->current_stage_id = $rule->to_stage_id;
            if ($rule->set_status_id !== null) {
                $lockedTransaction->status_id = $rule->set_status_id;
            }
            $lockedTransaction->save();

            $occurredAt = now();

            TransactionStageLog::create([
                'transaction_id' => $lockedTransaction->id,
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $rule->to_stage_id,
                'action' => $action,
                'comment' => $comment,
                'acted_by_user_id' => $actor->id,
                'acted_at' => $occurredAt,
            ]);

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

            return $lockedTransaction->refresh();
        });
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
}
