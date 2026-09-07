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
        // Stage 64, Track J: decision_withdrawn/decision_amended mirror
        // WorkflowService::hasTerminalStatus()'s own list — a non-creator
        // actor's assignment-based visibility should stop offering a
        // request an appeal has already overturned or amended, the same way
        // it already stops for cancelled/archived/in_execution/closed work.
        $terminalStatusIds = RequestStatus::query()
            ->whereIn('code', ['cancelled', 'archived', 'not_approved', 'in_execution', 'executed', 'completed_closed', 'decision_withdrawn', 'decision_amended'])
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
        // Stage 68 — same class of gap, same bounded fix. [D] Art. 21 puts the
        // completed file in front of the legal member ("يعرض الملف المستوفي
        // على العضو القانوني"), but R11 holds no workflow_transitions row, so
        // without this the legal-review queue would list files that 404 when
        // opened. Bounded to requests that have actually been handed to legal
        // review — either sitting at Art. 38's status 07 right now, or
        // carrying a review this member's tier already recorded — never a
        // general read of the whole pipeline.
        $isLegalReviewer = $actor->hasScreenPermission('legal_review', 'can_add');

        return $query->where(function (Builder $visible) use ($actor, $roleIds, $isSystemAdmin, $terminalStatusIds, $isSalariesReviewer, $isLegalReviewer) {
            $visible->where('requests.created_by_user_id', $actor->id);

            if ($isSalariesReviewer) {
                $visible->orWhere('requests.has_financial_impact', true);
            }

            if ($isLegalReviewer) {
                $visible->orWhere(function (Builder $underReview) {
                    $underReview->whereIn(
                        'requests.status_id',
                        RequestStatus::query()
                            ->where('code', CommitteeStatusService::LEGAL_REVIEW_STATUS)
                            ->select('id'),
                    );
                })->orWhereExists(function ($reviewed) {
                    $reviewed->selectRaw('1')
                        ->from('request_legal_reviews')
                        ->whereColumn('request_legal_reviews.request_id', 'requests.id');
                });
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
