<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves who may open a request in the direct workspace.
 *
 * A submitted request remains private to its filer and to صاحب العلاقة —
 * the employee it is about, who is the filer unless somebody filed on their
 * behalf (Stage 95) — until it reaches a workflow step the caller may
 * actually perform. Keeping this as a SQL scope
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
        // Stage 75/76 — the third and fourth instances of the same gap.
        // [D] Art. 37's second and third final paths close requests (عدم موافقة، عدم اختصاص) whose
        // statuses are in $terminalStatusIds above, and a Stage 54
        // pre-committee عدم اختصاص appears on no meeting screen at all, so the
        // closer would 404 on the very file Appendix 47 puts in front of them.
        // Bounded to Art. 37's own three closable states plus a file this
        // grant has already closed (so the card stays readable), never a
        // general read of the pipeline.
        //
        // Stage 76 added `in_execution` for the same reason one step earlier:
        // Appendix 70 requires the executing body to attach دليل التنفيذ, and
        // AttachmentController::store() runs through this very gate, so without
        // it the executor would 404 uploading the evidence their own action
        // demands. Same grant, same bound — the outputs screen's own states.
        //
        // Stage 77 added the approval-cycle statuses for the fifth instance of
        // the same gap: [D] Art. 30 assigns the approval register ("ويسجل مقرر
        // اللجنة … تاريخ ورود النتيجة … أي ملاحظات أو توجيهات") to المقرر (R02),
        // who holds no workflow_transitions row at either approval checkpoint —
        // so the recorder would 404 on the very file they are meant to record a
        // return from the approving body against.
        //
        // Stage 92 added `can_approve` alongside `can_edit`: R12 (HR, [F] step
        // 10's usual executing body) holds only the new execute/close tier, not
        // the rapporteur/chair `edit` actions, but needs the exact same reach to
        // find and open a file before it can act on it — there is no query for
        // "requests I am the executing body of" until execution is actually
        // recorded. Not a new disclosure: `reports`/`registers` are both
        // `view => '*'`, so R12 already sees this population there; this only
        // lets the direct workspace and AttachmentController::store() (which R12
        // needs for Appendix 70's evidence, per Stage 76) agree with what those
        // screens already show.
        $isCloser = $actor->hasScreenPermission('meeting_outputs', 'can_edit')
            || $actor->hasScreenPermission('meeting_outputs', 'can_approve');
        // Stage 87 — [F] names إدارة الموارد البشرية as co-owner of the study
        // at `observations`, but R12 holds no outbound workflow_transitions
        // row there at all: every rule at that stage is R02's (`forward`
        // returned to it in Stage 96; `request_edit`/`cancel` never left).
        // Giving R12 a
        // row of its own would either duplicate that ownership or contradict
        // it, so this is the same bounded, non-controlling reach the R11 and
        // SAL clauses already use for a party consulted on a stage without
        // moving it.
        $observationsStageId = WorkflowStage::query()->where('code', 'observations')->value('id');
        $isHrStudyCoOwner = $observationsStageId !== null && $actor->roles()->where('code', 'R12')->exists();
        // Stage 98 — [D] Appendix 6 row 3 makes الرئيس المباشر «مشارك» in
        // تجهيز الملف الوظيفي, which HR assembles at `receive_and_register`.
        // The manager holds a manager-gated row at the two stages before that
        // and none at this one, so the assignment clause below drops the file
        // out of their sight at exactly the moment they are supposed to be
        // contributing to it. Bounded to that one stage — the same shape as
        // the SAL, R11 and R12 clauses above, all of them a party consulted
        // on a stage without moving it.
        //
        // Read against صاحب العلاقة, not the filer: Stage 95 established that
        // «الرئيس المباشر» means the manager of the employee the file is
        // about, and WorkflowService/NotificationDispatcher already resolve
        // the gated rows the same way.
        $preparationStageId = WorkflowStage::query()
            ->where('code', EmploymentFilePreparationService::GATED_STAGE)
            ->value('id');

        return $query->where(function (Builder $visible) use ($actor, $roleIds, $isSystemAdmin, $terminalStatusIds, $isSalariesReviewer, $isLegalReviewer, $isCloser, $isHrStudyCoOwner, $observationsStageId, $preparationStageId) {
            // Stage 95 — the filer AND صاحب العلاقة. A request raised on an
            // employee's behalf is that employee's own file: Art. 101 tells
            // them about it and «متابعة طلباتي» lists it, so without this
            // they would be notified into a 404 — the same dead end the
            // bounded clauses above exist to close for consulted parties.
            // Both, not one: the clerk keeps sight of the work they filed.
            $visible->where('requests.created_by_user_id', $actor->id)
                ->orWhere('requests.subject_user_id', $actor->id);

            if ($isSalariesReviewer) {
                $visible->orWhere('requests.has_financial_impact', true);
            }

            if ($isHrStudyCoOwner) {
                $visible->orWhere('requests.current_stage_id', $observationsStageId);
            }

            if ($preparationStageId !== null) {
                $visible->orWhere(function (Builder $beingPrepared) use ($actor, $preparationStageId) {
                    $beingPrepared->where('requests.current_stage_id', $preparationStageId)
                        ->whereExists(function ($managed) use ($actor) {
                            $managed->selectRaw('1')
                                ->from('users as subjects')
                                ->whereColumn('subjects.id', 'requests.subject_user_id')
                                ->where('subjects.manager_id', $actor->id);
                        });
                });
            }

            if ($isLegalReviewer) {
                $visible->orWhere(function (Builder $underReview) {
                    $underReview->whereIn(
                        'requests.status_id',
                        RequestStatus::query()
                            ->whereIn('code', [
                                CommitteeStatusService::LEGAL_REVIEW_STATUS,
                                // Stage 78 — Art. 105's referral puts a file in
                                // front of the same person at a completely
                                // different point in its life, and the queue
                                // that lists it (legalReviewQueueQuery) would
                                // otherwise offer a row this gate 404s.
                                RequestSuspensionService::SUSPENDED_STATUS,
                            ])
                            ->select('id'),
                    );
                })->orWhereExists(function ($reviewed) {
                    $reviewed->selectRaw('1')
                        ->from('request_legal_reviews')
                        ->whereColumn('request_legal_reviews.request_id', 'requests.id');
                });
            }

            if ($isCloser) {
                $visible->orWhere(function (Builder $closable) {
                    $closable->whereIn(
                        'requests.status_id',
                        RequestStatus::query()
                            ->whereIn('code', [
                                ...RequestClosureService::CLOSABLE_STATUSES,
                                RequestExecutionService::EXECUTABLE_STATUS,
                                ...ApprovalReturnService::APPROVAL_CYCLE_STATUSES,
                                // Stage 78 — the Art. 103 certifier and
                                // the Art. 105 suspender need the same
                                // reach: `final_approved` is the origin
                                // of the execution referral they gate,
                                // and a suspended file is one they must
                                // be able to open to lift the hold.
                                'final_approved',
                                RequestSuspensionService::SUSPENDED_STATUS,
                            ])
                            ->select('id'),
                    )->orWhereNotNull('requests.closed_at')
                        // Stage 83 — the seventh instance of the same gap, and
                        // the first that a status list cannot bound, because
                        // its records span a file's whole life: [D] Appendix 30
                        // raises a document conflict before the agenda,
                        // Appendix 60 a special case at any point, Appendix 53
                        // a correction after the decision, Appendix 68 a
                        // withdrawal wherever the employee files one. Picking a
                        // status subset would have made المقرر able to record
                        // some of them and 404 on the rest.
                        //
                        // Bounded instead by Art. 20's own line: a file that
                        // has been granted its رقم إشاري is, in the article's
                        // words, قيدت لدى لجنة شؤون الموظفين — the committee's
                        // own file, which is exactly the population every one of
                        // those appendices addresses to المقرر. Before the قيد
                        // Art. 15 is explicit that the matter is not the
                        // committee's yet, so the intake half stays private to
                        // its creator and its own assignees.
                        //
                        // Not a new disclosure: the reports and registers
                        // screens already list this same population to every
                        // role. This only makes the detail workspace agree with
                        // what those screens already show R02 and R03.
                        ->orWhereNotNull('requests.reference_number');
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
                            // Deliberately a READ-only allowance, and no
                            // longer a fallback: R08 cannot use a
                            // manager-gated row (WorkflowService::actorMayUse
                            // has no admin override), but keeping these
                            // requests visible to an admin is what lets a
                            // stalled one be diagnosed — its available
                            // actions will simply be empty.
                            $manager->orWhere('workflow_transitions.requires_submitter_manager', true);

                            return;
                        }

                        $manager->orWhere(function ($requiresManager) use ($actor) {
                            $requiresManager
                                ->where('workflow_transitions.requires_submitter_manager', true)
                                // Stage 95 — the SUBJECT's manager, matching
                                // WorkflowService::actorIsSubjectsActiveManager()
                                // exactly. Resolving this from the creator
                                // while the gate resolves it from the subject
                                // would 404 the one person allowed to act.
                                ->whereExists(function ($subject) use ($actor) {
                                    $subject->selectRaw('1')
                                        ->from('users as request_subjects')
                                        ->whereColumn('request_subjects.id', 'requests.subject_user_id')
                                        ->where('request_subjects.manager_id', $actor->id)
                                        ->where('request_subjects.is_active', true)
                                        ->whereNull('request_subjects.deleted_at');
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
