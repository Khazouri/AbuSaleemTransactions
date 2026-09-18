<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Approval;
use App\Models\Request;
use App\Models\RequestStageLog;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Executes the transition map stored in workflow_transitions.
 *
 * This service is the single write boundary for moving an existing
 * request. The database row is locked before the rule is resolved so two
 * actors cannot both advance the same stage and leave contradictory histories.
 */
class WorkflowService
{
    // Stage 23 — every path that moves a request comes through this
    // service, so notifying from here means the detail screen, the approval
    // queues and the committee decision all announce a move exactly once.
    // The numbering generator is a dependency of this service, not of a
    // controller, because allocation has to happen wherever a move lands the
    // request on Art. 38's code 06 — today that is the قيد hop
    // (receive_and_register -> register, driven only by
    // RequestController::transition()), but the re-stamping approve hop out of
    // requirements_check is additionally reachable through
    // ApprovalController::store(), and a file that predates the قيد move mints
    // there. Allocating from any one controller would leave the others minting
    // no number at all.
    public function __construct(
        private readonly NotificationDispatcher $notifications,
        private readonly ArtifactNumberGenerator $numbers,
    ) {}

    /**
     * Workflow stage code => immutable business approval level.
     *
     * Stage order remains useful for routing, while this level gives reports
     * a stable reviewer-to-final sequence even if stage labels later change.
     */
    // Stage 57 removed competent_authority as a distinct approval tier — the
    // standard has no third post-committee approver, only the mayor and, when
    // required, the ministry (see AGENT_NOTES.md). A full chain is now 5
    // levels, not 6.
    private const APPROVAL_LEVELS = [
        'requirements_check' => 1,
        'receive_from_committee' => 2,
        'approval_by_authority' => 3,
        'local_governance_ministry' => 4,
        'final_approval_archiving' => 5,
    ];

    /**
     * Stage 64, Track J — stages `reopenAtStage()` refuses as a target,
     * whether the caller is Stage 64's `appeal_redo` outcome or Stage 66's
     * general reopen mechanism (both share this exclusion — see
     * reopenAtStage()'s own docblock). The three front-of-chain intake/
     * routing stages involve no decision-making a legal review could find
     * defective; the fourth, `receive_and_register`, has a real mechanical
     * gap — its only outbound actions are the three `register` rows gated by
     * `required_status_id` on one of the three `routed_to_*` statuses, which
     * neither `reopened_by_appeal` nor `reopened_for_representation` would
     * ever satisfy, stranding the request.
     */
    private const REDO_EXCLUDED_STAGE_CODES = [
        'receive_from_municipality', 'direct_manager_review', 'administrative_routing', 'receive_and_register',
    ];

    /**
     * Actions this actor may currently attempt, for rendering the workspace.
     *
     * The transition method repeats every check under a row lock; this is only
     * an ergonomic preview and must never be treated as authorization by itself.
     *
     * @return Collection<int, string>
     */
    public function availableActions(Request $requestRecord, User $actor): Collection
    {
        return $this->availableTransitions($requestRecord, $actor)
            ->pluck('action')
            ->values();
    }

    /**
     * Full rule metadata for rendering normal and exception actions distinctly.
     *
     * @return Collection<int, WorkflowTransition>
     */
    // Stage 16 — expose exception/comment metadata without weakening execution checks.
    public function availableTransitions(Request $requestRecord, User $actor): Collection
    {
        if (! $requestRecord->exists
            || ! $actor->exists
            || ! $actor->is_active
            || $requestRecord->current_stage_id === null
            || $this->hasTerminalStatus($requestRecord)) {
            return collect();
        }

        $roleIds = $actor->roles()->pluck('roles.id');

        return WorkflowTransition::query()
            ->where('from_stage_id', $requestRecord->current_stage_id)
            ->where(function ($query) use ($requestRecord) {
                $query->whereNull('request_type_id');

                if ($requestRecord->request_type_id !== null) {
                    $query->orWhere('request_type_id', $requestRecord->request_type_id);
                }
            })
            ->orderByRaw('order_no is null')
            ->orderBy('order_no')
            ->orderBy('id')
            ->get()
            ->groupBy('action')
            ->map(function (Collection $rules) use ($roleIds, $actor, $requestRecord) {
                $specific = $rules->whereNotNull('request_type_id');
                $applicable = $specific->isNotEmpty() ? $specific : $rules->whereNull('request_type_id');
                $allowed = $applicable
                    ->filter(fn (WorkflowTransition $rule) => $this->actorMayUse($rule, $requestRecord, $actor, $roleIds))
                    ->values();

                // An ambiguous rule is not genuinely available: execution
                // would fail closed, so the preview must not invite the click.
                return $allowed->count() === 1 ? $allowed->first() : null;
            })
            ->filter()
            ->filter(fn (WorkflowTransition $rule) => $rule->action !== 'deadline_expired' || $requestRecord->isOverdue())
            ->sortBy(fn (WorkflowTransition $rule) => [$rule->order_no === null, $rule->order_no, $rule->id])
            ->values();
    }

    /**
     * Move a request through one configured workflow transition.
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
        Request $requestRecord,
        string $action,
        User $actor,
        ?string $comment = null,
        ?string $signaturePath = null,
    ): Request {
        if (! $requestRecord->exists) {
            throw WorkflowTransitionException::requestNotPersisted();
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

        // Screen permissions decide who may approve at a level; this separate
        // conflict-of-interest rule still applies when one person holds both
        // the submitter and approver roles.
        if ($action === 'approve'
            && $requestRecord->created_by_user_id !== null
            && $requestRecord->created_by_user_id === $actor->id) {
            throw WorkflowTransitionException::cannotApproveOwnRequest();
        }

        // Read before the transaction so the قيد can be detected after it:
        // grantReferenceNumberIfRegistering() is one-way and idempotent (null
        // -> value, never value -> a different value), so this comparison is
        // true exactly once in a request's life, whichever hop happens to mint
        // it. Detecting the allocation rather than hardcoding the stage is
        // what keeps this correct for a file that predates the قيد move and
        // therefore mints on the approve hop instead.
        $referenceBeforeMove = $requestRecord->reference_number;

        [$movedRequest, $fromStage, $toStage] = DB::transaction(function () use ($requestRecord, $action, $actor, $comment, $signaturePath) {
            $lockedRequest = Request::query()
                ->lockForUpdate()
                ->findOrFail($requestRecord->getKey());

            if ($lockedRequest->current_stage_id === null) {
                throw WorkflowTransitionException::currentStageRequired();
            }

            if ($this->hasTerminalStatus($lockedRequest)) {
                throw WorkflowTransitionException::requestClosed();
            }

            $candidates = $this->transitionCandidates($lockedRequest, $action);

            if ($candidates->isEmpty()) {
                throw WorkflowTransitionException::transitionNotConfigured();
            }

            $roleIds = $actor->roles()->pluck('roles.id');
            $allowed = $candidates
                ->filter(fn (WorkflowTransition $rule) => $this->actorMayUse($rule, $lockedRequest, $actor, $roleIds))
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

            return $this->applyRule($lockedRequest, $rule, $action, $actor, $comment, $signaturePath);
        });

        // Outside the request: the notifications describe a move that has
        // already happened. The dispatcher queues them after-commit as well
        // (see its send()), so a caller that wraps this in a request of
        // its own — DecisionController does — still can't announce a decision
        // that later rolls back.
        $this->notifications->stageChanged($movedRequest, $actor, $action, $fromStage, $toStage);

        // The submitter holds a PM-RCV receipt until this moment and would
        // otherwise never learn that the number they were given has been
        // superseded — every later notice quotes the PM-COM one. Deliberately
        // its own event rather than folded into Art. 101's moment 1, which
        // also fires here and keeps [D]'s own wording; see AGENT_NOTES.md for
        // why both are sent and must not be collapsed into one.
        if ($referenceBeforeMove === null && $movedRequest->reference_number !== null) {
            $this->notifications->referenceAssigned($movedRequest, $actor);
        }

        return $movedRequest;
    }

    /**
     * Perform a system-initiated hop through ONE already-resolved rule, with
     * NO actor/role/manager check — for hand-offs the code itself decides on,
     * not a human choosing among available actions.
     *
     * Diagram-alignment redesign: `RequestController::store()` uses this
     * to move a freshly created request straight from intake into
     * `direct_manager_review`. Intake itself may be performed by any of
     * R01-R06 (see ScreenRolePermissionSeeder's `request_intake` grant),
     * but the seeded `submit` row is R01-gated — routing that hop through
     * transition()'s normal actor check would 403 an R02+ clerk filing on
     * someone else's behalf, even though the hop is not that clerk's action
     * at all. Resolving the canonical (non-exception) rule directly and
     * skipping actorMayUse() entirely is what makes this safe: the caller,
     * not a role check, is vouching that this specific hop should happen.
     *
     * Must be called from within an existing DB transaction. It does not open
     * its own and does not lock the row — the caller just created the
     * request in that same transaction, so nothing else can be racing it.
     *
     * @throws WorkflowTransitionException
     */
    public function applySystemTransition(
        Request $requestRecord,
        string $fromStageCode,
        string $action,
        User $actor,
        ?string $comment = null,
    ): Request {
        $fromStage = WorkflowStage::query()->where('code', $fromStageCode)->firstOrFail();

        $candidates = WorkflowTransition::query()
            ->where('from_stage_id', $fromStage->id)
            ->where('action', $action)
            ->where('is_exception', false)
            ->where(function ($query) use ($requestRecord) {
                $query->whereNull('request_type_id');

                if ($requestRecord->request_type_id !== null) {
                    $query->orWhere('request_type_id', $requestRecord->request_type_id);
                }
            })
            ->get();

        $specific = $candidates->whereNotNull('request_type_id');
        $applicable = $specific->isNotEmpty() ? $specific : $candidates->whereNull('request_type_id');

        if ($applicable->isEmpty()) {
            throw WorkflowTransitionException::transitionNotConfigured();
        }

        // A system hop names its own action/from-stage, so more than one
        // surviving row means a seeding mistake, not an actor ambiguity —
        // fail closed exactly like the actor-checked path does.
        if ($applicable->count() > 1) {
            throw WorkflowTransitionException::ambiguousConfiguration();
        }

        /** @var WorkflowTransition $rule */
        $rule = $applicable->first();

        [$movedRequest, $fromStageModel, $toStageModel] = $this->applyRule(
            $requestRecord,
            $rule,
            $action,
            $actor,
            $comment,
            null,
        );

        $this->notifications->stageChanged($movedRequest, $actor, $action, $fromStageModel, $toStageModel);

        return $movedRequest;
    }

    /**
     * Stage 64, Track J — the one appeal outcome (`appeal_redo`) that must
     * genuinely re-enter this state machine, at a stage a human names (the
     * one the legal review found a defect at), not the next stage in
     * sequence and not a restart from scratch. See AppealOutcomeExecutor,
     * the only caller of the default `$action`/`$statusCode`/
     * `$enforceBackwardOnly` shape.
     *
     * Stage 66, Track J generalizes this into the [D] Arts. 34–37/78–79
     * reopen mechanism proper: RequestController::reopen() calls this same
     * method with `action: 'reopen'`, `statusCode: 'reopened_for_representation'`,
     * `enforceBackwardOnly: false` — a concluded request can be re-presented
     * either backward (a defect found before an already-reached later stage,
     * same direction appeal_redo always moves) or forward (e.g. a request
     * cancelled early in intake needs to resume past where it stopped), so
     * the "must not exceed current order" guard that makes sense for
     * appeal_redo's "undo a specific defect" framing would wrongly refuse
     * the forward case for a plain re-presentation.
     *
     * Unlike transition(), no workflow_transitions row is resolved or
     * required — an arbitrary jump is not something any configured rule
     * could match, so this is an out-of-band, caller-authorized override:
     * the caller, not a role/rule check, is vouching that this specific
     * reopening should happen. Unlike applySystemTransition() (a similar
     * "caller vouches" shape used for a very different reason — the Stage 57
     * intake auto-hop), this method opens its own transaction and locks the
     * row itself: it acts on an existing, potentially long-lived request
     * rather than one the caller just created microseconds ago in the same
     * transaction, so it cannot assume away a concurrent writer.
     *
     * Every other write shape (both history rows, the stageChanged()
     * notification) still mirrors applyRule()'s, so a reopening leaves the
     * exact same audit trail an ordinary transition would.
     *
     * @throws WorkflowTransitionException
     */
    public function reopenAtStage(
        Request $requestRecord,
        WorkflowStage $targetStage,
        User $actor,
        string $reason,
        string $action = 'appeal_redo',
        string $statusCode = 'reopened_by_appeal',
        bool $enforceBackwardOnly = true,
    ): Request {
        if (in_array($targetStage->code, self::REDO_EXCLUDED_STAGE_CODES, true)) {
            throw WorkflowTransitionException::invalidRedoStage();
        }

        [$movedRequest, $fromStageId] = DB::transaction(function () use ($requestRecord, $targetStage, $actor, $reason, $action, $statusCode, $enforceBackwardOnly) {
            $lockedRequest = Request::query()
                ->lockForUpdate()
                ->findOrFail($requestRecord->getKey());

            $currentOrder = $lockedRequest->currentStage?->order_no;
            if ($enforceBackwardOnly && $currentOrder !== null && $targetStage->order_no > $currentOrder) {
                throw WorkflowTransitionException::redoStageMustPrecedeCurrent();
            }

            $fromStageId = $lockedRequest->current_stage_id;
            $fromStatusId = $lockedRequest->status_id;
            $statusId = RequestStatus::query()->where('code', $statusCode)->value('id');

            $lockedRequest->current_stage_id = $targetStage->id;
            $lockedRequest->status_id = $statusId;
            $lockedRequest->save();

            $occurredAt = now();

            RequestStageLog::create([
                'request_id' => $lockedRequest->id,
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $targetStage->id,
                'action' => $action,
                'comment' => $reason,
                'acted_by_user_id' => $actor->id,
                'acted_at' => $occurredAt,
            ]);

            RequestStatusHistory::create([
                'request_id' => $lockedRequest->id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $statusId,
                'reason' => $reason,
                'changed_by_user_id' => $actor->id,
                'changed_at' => $occurredAt,
            ]);

            return [$lockedRequest->refresh(), $fromStageId];
        });

        $this->notifications->stageChanged($movedRequest, $actor, $action, WorkflowStage::find($fromStageId), $targetStage);

        return $movedRequest;
    }

    /**
     * Apply an already-resolved rule: validate its own constraints (comment,
     * signature, overdue-only), write the stage/status move and its two
     * history rows, and hand back the moved request plus both stage
     * models. This is the single write shape both transition() (after its
     * actor check picks a rule) and applySystemTransition() (which skips the
     * actor check entirely) share — see AGENT_NOTES.md: WorkflowService is
     * the single write boundary, so this shape must not be duplicated.
     *
     * $lockedRequest is trusted to already reflect the current row (row
     * lock held by transition(), or a same-request fresh row for a system
     * hop) — this method does not re-fetch or re-lock it.
     *
     * @return array{0: Request, 1: ?WorkflowStage, 2: ?WorkflowStage}
     */
    /**
     * The قيد: allocate the committee reference number the moment, and only
     * the moment, a move lands the request on Art. 38's code 06 (مستوفية
     * ومقيدة). Art. 15 is explicit that handing the request to the direct
     * manager is not a قيد, which is why intake mints only a receipt.
     *
     * Keyed off the DESTINATION STATUS rather than a hardcoded stage/action
     * pair so the قيد follows the seeded map: whichever rule the seeder says
     * reaches code 06 is the rule that registers, and re-seeding that map
     * moves this with it. That is not decoration — it is how the قيد was
     * moved from Stage 70's `requirements_check -> approve` to the receiving
     * body's own `register` action without editing a line of this method.
     *
     * Note the consequence, which is deliberate and is recorded in
     * AGENT_NOTES.md: the قيد now precedes [D] Appendix 63's بوابة 1 (the
     * completeness gate on the approve hop), so a file is numbered before its
     * documents are verified. That gate could not move with it — only R02 may
     * record it, and R02 cannot open the file while it is still with the
     * receiving body.
     *
     * Allocation is conditional on there being no reference yet. Art. 99
     * ("يكون لكل معاملة رقم واحد طوال دورة حياتها") and النموذج 05 ("ولا يجوز
     * منح أكثر من رقم أساسي لنفس المعاملة لمجرد انتقالها بين مراحل العمل") both
     * forbid a second number, and this is the path a file re-walks every time
     * it comes back from `return_missing_docs`.
     *
     * The caller already holds the row lock and is inside the transition's own
     * DB transaction, which is where ArtifactNumberGenerator needs to run.
     */
    private function grantReferenceNumberIfRegistering(Request $lockedRequest, ?int $statusId): void
    {
        if ($statusId === null || $lockedRequest->reference_number !== null) {
            return;
        }

        $registeredStatusId = RequestStatus::query()->where('code', 'registered')->value('id');

        if ($registeredStatusId === null || $statusId !== $registeredStatusId) {
            return;
        }

        $lockedRequest->reference_number = $this->numbers->nextRequestReference();
    }

    private function applyRule(
        Request $lockedRequest,
        WorkflowTransition $rule,
        string $action,
        User $actor,
        ?string $comment,
        ?string $signaturePath,
    ): array {
        if ($rule->action === 'deadline_expired' && ! $lockedRequest->isOverdue()) {
            throw WorkflowTransitionException::deadlineNotExpired();
        }

        if ($rule->requires_comment && $comment === null) {
            throw WorkflowTransitionException::commentRequired();
        }

        $approvalLevel = $this->approvalLevel($rule);
        if ($approvalLevel !== null && $signaturePath === null) {
            throw WorkflowTransitionException::signatureRequired();
        }

        $fromStageId = $lockedRequest->current_stage_id;
        $fromStatusId = $lockedRequest->status_id;
        $toStageId = $this->destinationStageId($lockedRequest, $rule);
        $statusId = $this->destinationStatusId($lockedRequest, $rule);

        $lockedRequest->current_stage_id = $toStageId;
        if ($statusId !== null) {
            $lockedRequest->status_id = $statusId;
        }
        $this->grantReferenceNumberIfRegistering($lockedRequest, $statusId);
        $lockedRequest->save();

        $occurredAt = now();

        RequestStageLog::create([
            'request_id' => $lockedRequest->id,
            'from_stage_id' => $fromStageId,
            'to_stage_id' => $toStageId,
            'action' => $action,
            'comment' => $comment,
            'acted_by_user_id' => $actor->id,
            'acted_at' => $occurredAt,
        ]);

        if ($approvalLevel !== null) {
            Approval::create([
                'request_id' => $lockedRequest->id,
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
        if ($statusId !== null) {
            RequestStatusHistory::create([
                'request_id' => $lockedRequest->id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $statusId,
                'reason' => $comment,
                'changed_by_user_id' => $actor->id,
                'changed_at' => $occurredAt,
            ]);
        }

        // The stage rows travel out so the notification can name where the
        // work came from without a second lookup.
        return [
            $lockedRequest->refresh(),
            WorkflowStage::find($fromStageId),
            WorkflowStage::find($toStageId),
        ];
    }

    /**
     * The single predicate deciding whether $actor may use $rule right now,
     * shared by BOTH transition() and availableTransitions(). This is the
     * load-bearing correctness property from the direct-manager redesign
     * (see AGENT_NOTES.md): if the preview and the execution check ever
     * diverge, the UI could offer a button execution refuses, or hide one it
     * would accept.
     *
     * All three gates must hold:
     *   - the existing role check, preserved exactly;
     *   - if the row is manager-gated, the actor must be the request
     *     creator's active, non-deleted manager, with no fallback of any
     *     kind: only that manager may delegate, and a submitter with no
     *     manager assigned (or whose manager has left or been deactivated)
     *     has a request that cannot be delegated at all;
     *   - if the row is status-gated, the request's CURRENT status must
     *     match — this is what makes three-way administrative routing
     *     enforceable rather than decorative.
     */
    private function actorMayUse(WorkflowTransition $rule, Request $requestRecord, User $actor, Collection $actorRoleIds): bool
    {
        if ($rule->action === 'approve'
            && $requestRecord->created_by_user_id !== null
            && $requestRecord->created_by_user_id === $actor->id) {
            return false;
        }

        if ($rule->required_role_id !== null && ! $actorRoleIds->contains($rule->required_role_id)) {
            return false;
        }

        // A manager-gated row is the submitter's own direct manager's
        // decision and nobody else's. There is deliberately NO admin
        // override here: a request whose creator has no live manager link
        // cannot be delegated at all, which is the rule as stated rather
        // than a gap for R08 to paper over. The consequence is real and
        // intended — such a request stalls at direct_manager_review with no
        // action available to anyone, an admin included. Assigning the
        // employee a manager on the Users screen is what releases it.
        if ($rule->requires_submitter_manager
            && ! $this->actorIsCreatorsActiveManager($requestRecord, $actor)) {
            return false;
        }

        if ($rule->required_status_id !== null && $rule->required_status_id !== $requestRecord->status_id) {
            return false;
        }

        return true;
    }

    /**
     * Is $actor the request creator's manager, and is that manager link
     * actually live — not soft-deleted (the default Eloquent scope already
     * excludes trashed rows) and not deactivated? A dangling manager_id
     * (the manager left, or was never set) simply fails the gate — there is
     * no override to fall through to, so the request cannot be delegated
     * until a live manager is assigned.
     */
    private function actorIsCreatorsActiveManager(Request $requestRecord, User $actor): bool
    {
        $creatorId = $requestRecord->created_by_user_id;

        if ($creatorId === null) {
            return false;
        }

        $managerId = User::query()->whereKey($creatorId)->value('manager_id');

        if ($managerId === null || (int) $managerId !== $actor->id) {
            return false;
        }

        return User::query()->whereKey($managerId)->where('is_active', true)->exists();
    }

    /**
     * Resolve rules for this type, letting type-specific rows replace generic
     * rows for the same stage/action rather than competing with them.
     *
     * @return Collection<int, WorkflowTransition>
     */
    private function transitionCandidates(Request $requestRecord, string $action): Collection
    {
        $candidates = WorkflowTransition::query()
            ->where('from_stage_id', $requestRecord->current_stage_id)
            ->where('action', $action)
            ->where(function ($query) use ($requestRecord) {
                $query->whereNull('request_type_id');

                if ($requestRecord->request_type_id !== null) {
                    $query->orWhere('request_type_id', $requestRecord->request_type_id);
                }
            })
            ->get();

        $specific = $candidates->whereNotNull('request_type_id')->values();

        return $specific->isNotEmpty()
            ? $specific
            : $candidates->whereNull('request_type_id')->values();
    }

    /**
     * Stage 18 conditional branch, restructured by Stage 57: after the admin
     * manager approves, low-grade work skips the ministry checkpoint
     * entirely and lands directly at final approval — the standard's
     * mayor-only path, with no third approving authority in between.
     */
    private function destinationStageId(Request $requestRecord, WorkflowTransition $rule): int
    {
        if (! $this->bypassesMinistryApproval($requestRecord, $rule)) {
            return $rule->to_stage_id;
        }

        return WorkflowStage::query()
            ->where('code', 'final_approval_archiving')
            ->firstOrFail()
            ->id;
    }

    /**
     * Stage 57 — the bypass above also has to override the status, not just
     * the stage: the row's own `set_status_id` (`approved`, still meaning
     * "one more approval pending" on the normal ministry-bound path) would
     * misrepresent the bypass arrival, where nothing is left pending.
     */
    private function destinationStatusId(Request $requestRecord, WorkflowTransition $rule): ?int
    {
        if (! $this->bypassesMinistryApproval($requestRecord, $rule)) {
            return $rule->set_status_id;
        }

        return RequestStatus::query()->where('code', 'final_approved')->value('id');
    }

    private function bypassesMinistryApproval(Request $requestRecord, WorkflowTransition $rule): bool
    {
        return $rule->action === 'approve'
            && $rule->fromStage()->value('code') === 'approval_by_authority'
            && ! $requestRecord->requiresMinistryApproval();
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
     * `decision_withdrawn`/`decision_amended` (Stage 64, Track J) are here
     * too: once an appeal has finally overturned or amended a decision, the
     * appeal body's own resolution is the final word — no further ordinary
     * workflow move follows.
     */
    private function hasTerminalStatus(Request $requestRecord): bool
    {
        return $requestRecord->status()
            ->whereIn('code', ['cancelled', 'archived', 'not_approved', 'in_execution', 'executed', 'completed_closed', 'decision_withdrawn', 'decision_amended'])
            ->exists();
    }
}
