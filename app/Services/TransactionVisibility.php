<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves who may open a transaction in the direct workspace.
 *
 * A submitted request remains private to its creator until it reaches a
 * workflow step the caller may actually perform. Keeping this as a SQL scope
 * preserves pagination correctness while making every direct transaction
 * endpoint share the same rule.
 */
class TransactionVisibility
{
    /**
     * Limit a transaction query to the caller's submissions and active work.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function apply(Builder $query, User $actor): Builder
    {
        if (! $actor->is_active) {
            return $query->whereRaw('1 = 0');
        }

        $roleIds = $actor->roles()->pluck('roles.id');
        $isSystemAdmin = $actor->roles()->where('code', 'R08')->exists();
        $terminalStatusIds = TransactionStatus::query()
            ->whereIn('code', ['cancelled', 'archived', 'in_execution', 'completed_closed'])
            ->select('id');

        return $query->where(function (Builder $visible) use ($actor, $roleIds, $isSystemAdmin, $terminalStatusIds) {
            $visible->where('transactions.created_by_user_id', $actor->id)
                ->orWhereExists(function ($assignment) use ($actor, $roleIds, $isSystemAdmin, $terminalStatusIds) {
                    $assignment->selectRaw('1')
                        ->from('workflow_transitions')
                        ->whereColumn('workflow_transitions.from_stage_id', 'transactions.current_stage_id')
                        // Type-specific rules replace the generic rule with the
                        // same action, exactly as WorkflowService resolves them.
                        ->where(function ($type) {
                            $type->where(function ($specific) {
                                $specific->whereNotNull('workflow_transitions.transaction_type_id')
                                    ->whereColumn('workflow_transitions.transaction_type_id', 'transactions.transaction_type_id');
                            })->orWhere(function ($generic) {
                                $generic->whereNull('workflow_transitions.transaction_type_id')
                                    ->whereNotExists(function ($override) {
                                        $override->selectRaw('1')
                                            ->from('workflow_transitions as type_overrides')
                                            ->whereColumn('type_overrides.from_stage_id', 'transactions.current_stage_id')
                                            ->whereColumn('type_overrides.action', 'workflow_transitions.action')
                                            ->whereColumn('type_overrides.transaction_type_id', 'transactions.transaction_type_id');
                                    });
                            });
                        })
                        ->where(function ($role) use ($roleIds) {
                            $role->whereNull('workflow_transitions.required_role_id');

                            if ($roleIds->isNotEmpty()) {
                                $role->orWhereIn('workflow_transitions.required_role_id', $roleIds);
                            }
                        })
                        ->where(function ($manager) use ($actor, $isSystemAdmin) {
                            $manager->where('workflow_transitions.requires_submitter_manager', false);

                            if ($isSystemAdmin) {
                                // R08 is the documented fallback when a
                                // manager-gated request would otherwise stall.
                                $manager->orWhere('workflow_transitions.requires_submitter_manager', true);

                                return;
                            }

                            $manager->orWhere(function ($requiresManager) use ($actor) {
                                $requiresManager
                                    ->where('workflow_transitions.requires_submitter_manager', true)
                                    ->whereExists(function ($creator) use ($actor) {
                                        $creator->selectRaw('1')
                                            ->from('users as transaction_creators')
                                            ->whereColumn('transaction_creators.id', 'transactions.created_by_user_id')
                                            ->where('transaction_creators.manager_id', $actor->id)
                                            ->where('transaction_creators.is_active', true)
                                            ->whereNull('transaction_creators.deleted_at');
                                    });
                            });
                        })
                        ->where(function ($status) {
                            $status->whereNull('workflow_transitions.required_status_id')
                                ->orWhereColumn('workflow_transitions.required_status_id', 'transactions.status_id');
                        })
                        ->where(function ($overdue) {
                            $overdue->where('workflow_transitions.action', '!=', 'deadline_expired')
                                ->orWhereNotNull('transactions.overdue_at');
                        })
                        ->whereNotIn('transactions.status_id', $terminalStatusIds);
                });
        });
    }

    public function canView(User $actor, Transaction $transaction): bool
    {
        return $this->apply(Transaction::query()->whereKey($transaction->getKey()), $actor)->exists();
    }
}
