<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves who may open a request in the direct workspace.
 *
 * A submitted request remains private to its creator until it reaches a
 * workflow step the caller may actually perform. Keeping this as a SQL scope
 * preserves pagination correctness while making every direct request
 * endpoint share the same rule.
 */
class RequestVisibility
{
    /**
     * Limit a request query to the caller's submissions and active work.
     *
     * @param  Builder<Request>  $query
     * @return Builder<Request>
     */
    public function apply(Builder $query, User $actor): Builder
    {
        if (! $actor->is_active) {
            return $query->whereRaw('1 = 0');
        }

        $roleIds = $actor->roles()->pluck('roles.id');
        $isSystemAdmin = $actor->roles()->where('code', 'R08')->exists();
        $terminalStatusIds = RequestStatus::query()
            ->whereIn('code', ['cancelled', 'archived', 'in_execution', 'completed_closed'])
            ->select('id');
        // Stage 47 — قسم المرتبات والمزايا has no role tied to any
        // workflow_transitions row, so without this a flagged request's
        // notification would be a dead end (a 404 on open). Bounded to
        // exactly the requests this department was actually notified about —
        // not a general committee-style bypass — and, like creator
        // visibility below, not stage- or terminal-status-gated: their
        // interest in a request they were consulted on doesn't expire.
        $salariesDepartmentId = Department::query()->where('code', 'SAL')->value('id');
        $isSalariesReviewer = $salariesDepartmentId !== null && $actor->department_id === $salariesDepartmentId;

        return $query->where(function (Builder $visible) use ($actor, $roleIds, $isSystemAdmin, $terminalStatusIds, $isSalariesReviewer) {
            $visible->where('requests.created_by_user_id', $actor->id);

            if ($isSalariesReviewer) {
                $visible->orWhere('requests.has_financial_impact', true);
            }

            $visible->orWhereExists(function ($assignment) use ($actor, $roleIds, $isSystemAdmin, $terminalStatusIds) {
                $assignment->selectRaw('1')
                    ->from('workflow_transitions')
                    ->whereColumn('workflow_transitions.from_stage_id', 'requests.current_stage_id')
                    // Type-specific rules replace the generic rule with the
                    // same action, exactly as WorkflowService resolves them.
                    ->where(function ($type) {
                        $type->where(function ($specific) {
                            $specific->whereNotNull('workflow_transitions.request_type_id')
                                ->whereColumn('workflow_transitions.request_type_id', 'requests.request_type_id');
                        })->orWhere(function ($generic) {
                            $generic->whereNull('workflow_transitions.request_type_id')
                                ->whereNotExists(function ($override) {
                                    $override->selectRaw('1')
                                        ->from('workflow_transitions as type_overrides')
                                        ->whereColumn('type_overrides.from_stage_id', 'requests.current_stage_id')
                                        ->whereColumn('type_overrides.action', 'workflow_transitions.action')
                                        ->whereColumn('type_overrides.request_type_id', 'requests.request_type_id');
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
                                        ->from('users as request_creators')
                                        ->whereColumn('request_creators.id', 'requests.created_by_user_id')
                                        ->where('request_creators.manager_id', $actor->id)
                                        ->where('request_creators.is_active', true)
                                        ->whereNull('request_creators.deleted_at');
                                });
                        });
                    })
                    ->where(function ($status) {
                        $status->whereNull('workflow_transitions.required_status_id')
                            ->orWhereColumn('workflow_transitions.required_status_id', 'requests.status_id');
                    })
                    ->where(function ($overdue) {
                        $overdue->where('workflow_transitions.action', '!=', 'deadline_expired')
                            ->orWhereNotNull('requests.overdue_at');
                    })
                    ->whereNotIn('requests.status_id', $terminalStatusIds);
            });
        });
    }

    public function canView(User $actor, Request $requestRecord): bool
    {
        return $this->apply(Request::query()->whereKey($requestRecord->getKey()), $actor)->exists();
    }
}
