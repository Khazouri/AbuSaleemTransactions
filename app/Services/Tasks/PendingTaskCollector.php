<?php

namespace App\Services\Tasks;

use App\Http\Controllers\Api\ApprovalController;
use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\Committee;
use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\MeetingMinuteSignature;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\User;
use App\Models\WorkflowTransition;
use App\Services\CommitteeStatusService;
use App\Services\DecisionEligibility;
use App\Services\Lifecycle\SpecialCaseRules;
use App\Services\MeetingVisibility;
use App\Services\RequestClosureService;
use App\Services\RequestVisibility;
use App\Services\WorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Everything waiting on one person, in one place.
 *
 * Replaces five separate per-role queues, each of which lived on its own screen
 * and was visible only to the one role that held it — so nobody had an answer
 * to "what is waiting for me" that spanned their whole job.
 *
 * Two rules keep it honest, and both matter more than the aggregation itself:
 *
 *  - It never re-derives a queue. Each source calls the query builder that
 *    already owns that rule (DecisionEligibility for votes, CommitteeStatusService
 *    for candidates and legal review, this class's own approval query, which
 *    ApprovalController now calls too). A worklist offering a row its target
 *    endpoint refuses would be worse than no worklist at all.
 *  - A source is collected ONLY if the actor holds the grant that lets them act
 *    on it. That is what makes the inbox per-user by construction rather than by
 *    filtering afterwards, and it is why every row can be acted on by whoever is
 *    looking at it.
 *
 * The inbox lists; it does not act. Every row carries a `route` naming the screen
 * that already owns that action, so each action keeps exactly one implementation.
 */
class PendingTaskCollector
{
    /** Per source, so one busy queue cannot crowd out the rest. */
    private const PER_SOURCE_LIMIT = 100;

    /**
     * Mirrors ApprovalController::index()'s own exclusions — a request that has
     * left the pipeline is nobody's pending work.
     */
    private const TERMINAL_STATUSES = [
        'cancelled', 'archived', 'not_approved', 'in_execution',
        'executed', 'completed_closed', 'decision_withdrawn', 'decision_amended',
    ];

    /**
     * Appeal status => the `appeals,edit` step it is waiting for. The two
     * statuses missing here are waiting on somebody else: `legal_review` on
     * nomination and the committee, `notified_closed` on nobody.
     * `committee_presentation` is resolved per row (execute, then close).
     */
    private const APPEAL_NEXT_STEP = [
        'submitted' => 'verify',
        'formal_verification' => 'jurisdiction_test',
        'file_assembly' => 'legal_review',
        'rejected' => 'close',
        'outside_jurisdiction' => 'close',
    ];

    public function __construct(
        private readonly CommitteeStatusService $committeeStatus,
        private readonly DecisionEligibility $decisions,
        private readonly MeetingVisibility $meetings,
        private readonly RequestVisibility $requests,
        private readonly WorkflowService $workflow,
    ) {}

    /**
     * @return array{sources: list<array<string, mixed>>, total: int, truncated: bool}
     */
    public function collect(User $actor): array
    {
        $sources = array_values(array_filter([
            $this->approvals($actor),
            $this->votes($actor),
            $this->candidates($actor),
            $this->legalReviews($actor),
            $this->minuteSignatures($actor),
            $this->meetingInvitations($actor),
            $this->meetingsDue($actor),
            $this->myCompletions($actor),
            $this->workflowSteps($actor),
            $this->overdue($actor),
            $this->meetingDuties($actor),
            $this->postDecision($actor),
            $this->openRecords($actor),
            $this->appeals($actor),
        ]));

        return [
            'sources' => $sources,
            'total' => array_sum(array_column($sources, 'count')),
            'truncated' => (bool) array_sum(array_column($sources, 'truncated')),
        ];
    }

    /**
     * Requests sitting at a checkpoint this actor may approve.
     *
     * Public, and ApprovalController::index() narrows it to a single stage
     * rather than keeping its own copy — the queue and the inbox have to agree
     * about what is pending, or approving from one would leave a ghost in the
     * other.
     *
     * @param  list<string>  $stageCodes
     * @return Builder<Request>
     */
    public function approvalsQuery(User $actor, array $stageCodes): Builder
    {
        return Request::query()
            ->whereHas('currentStage', fn (Builder $stage) => $stage->whereIn('code', $stageCodes))
            // Never advertise a record whose only available actor is also its
            // filer or صاحب العلاقة; WorkflowService repeats this same
            // prohibition when writing (Stage 95 widened both together).
            ->where(fn (Builder $query) => $query
                ->whereNull('created_by_user_id')
                ->orWhere('created_by_user_id', '!=', $actor->id))
            ->where(fn (Builder $query) => $query
                ->whereNull('subject_user_id')
                ->orWhere('subject_user_id', '!=', $actor->id))
            ->whereDoesntHave('status', fn (Builder $status) => $status->whereIn('code', self::TERMINAL_STATUSES));
    }

    /** @return array<string, mixed>|null */
    private function approvals(User $actor): ?array
    {
        $stages = [];
        foreach (ApprovalController::LEVELS as $level) {
            if ($actor->hasScreenPermission($level['screen'], 'can_approve')) {
                $stages[] = $level['stage'];
            }
        }

        if ($stages === []) {
            return null;
        }

        $rows = $this->approvalsQuery($actor, $stages)
            ->with(['currentStage:id,code,name_ar,name_en', 'requestType:id,name_ar,name_en'])
            ->orderBy('submitted_at')
            ->limit(self::PER_SOURCE_LIMIT + 1)
            ->get();

        return $this->source('approval', $rows, fn (Request $r) => [
            'title' => $r->title,
            'reference_number' => $r->trackingNumber(),
            'subject' => $r->currentStage?->name_ar,
            'waiting_since' => $r->submitted_at?->toIso8601String(),
            'due_at' => $r->due_date?->toIso8601String(),
            'is_overdue' => $r->overdue_at !== null,
            'route' => ['name' => 'request_details', 'params' => ['id' => $r->id], 'query' => ['decide' => 1]],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function votes(User $actor): ?array
    {
        if (! $actor->hasScreenPermission('decisions', 'can_add')) {
            return null;
        }

        $rows = $this->decisions->pendingVotesQuery($actor)
            ->with(['request:id,reference_number,title', 'meeting:id,title,scheduled_at'])
            ->limit(self::PER_SOURCE_LIMIT + 1)
            ->get();

        return $this->source('vote', $rows, fn ($item) => [
            'title' => $item->request?->title ?? $item->subject,
            'reference_number' => $item->request?->reference_number,
            'subject' => $item->meeting?->title,
            'waiting_since' => $item->meeting?->scheduled_at?->toIso8601String(),
            'due_at' => $item->meeting?->scheduled_at?->toIso8601String(),
            'is_overdue' => false,
            // Decision wizard — sub-project 2: the item's wizard opens straight away.
            'route' => ['name' => 'meeting_live', 'query' => ['meeting' => $item->meeting_id, 'item' => $item->id, 'decide' => 1]],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function candidates(User $actor): ?array
    {
        // The `edit` tier rather than the '*' view tier: a candidate is only a
        // task for whoever can actually nominate, defer or return it.
        if (! $actor->hasScreenPermission('committee_candidates', 'can_edit')) {
            return null;
        }

        $rows = $this->committeeStatus->candidatesQuery()
            ->with(['status:id,code,name_ar,name_en', 'requestType:id,name_ar,name_en'])
            ->orderBy('submitted_at')
            ->limit(self::PER_SOURCE_LIMIT + 1)
            ->get();

        return $this->source('candidate', $rows, fn (Request $r) => [
            'title' => $r->title,
            'reference_number' => $r->trackingNumber(),
            'subject' => $r->status?->name_ar,
            'waiting_since' => $r->submitted_at?->toIso8601String(),
            'due_at' => $r->due_date?->toIso8601String(),
            'is_overdue' => $r->overdue_at !== null,
            // Decision wizard — sub-project 2: a candidate's moves are taken on the file.
            'route' => ['name' => 'request_details', 'params' => ['id' => $r->id], 'query' => ['decide' => 1]],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function legalReviews(User $actor): ?array
    {
        if (! $actor->hasScreenPermission('legal_review', 'can_add')) {
            return null;
        }

        $rows = $this->committeeStatus->legalReviewQueueQuery()
            ->with(['status:id,code,name_ar,name_en', 'requestType:id,name_ar,name_en'])
            ->orderBy('submitted_at')
            ->limit(self::PER_SOURCE_LIMIT + 1)
            ->get();

        return $this->source('legal_review', $rows, fn (Request $r) => [
            'title' => $r->title,
            'reference_number' => $r->trackingNumber(),
            'subject' => $r->requestType?->name_ar,
            'waiting_since' => $r->submitted_at?->toIso8601String(),
            'due_at' => $r->due_date?->toIso8601String(),
            'is_overdue' => $r->overdue_at !== null,
            'route' => ['name' => 'request_details', 'params' => ['id' => $r->id], 'query' => ['decide' => 1]],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function minuteSignatures(User $actor): ?array
    {
        if (! $actor->hasScreenPermission('meeting_minutes', 'can_add')) {
            return null;
        }

        $rows = MeetingMinuteSignature::query()
            ->where('user_id', $actor->id)
            ->whereNull('signed_at')
            ->whereHas('minutes', fn (Builder $minutes) => $minutes
                ->where('status', MeetingMinutes::STATUS_PENDING_SIGNATURES)
                // A sitting whose committee the actor has since left is no
                // longer theirs to sign, and the screen would 404 on it.
                ->whereHas('meeting', fn (Builder $meeting) => $this->meetings->apply($meeting, $actor)))
            ->with('minutes.meeting:id,title,scheduled_at')
            ->limit(self::PER_SOURCE_LIMIT + 1)
            ->get();

        return $this->source('minutes_signature', $rows, fn ($signature) => [
            'title' => $signature->minutes?->meeting?->title,
            'reference_number' => $signature->minutes?->minutes_number,
            'subject' => null,
            'waiting_since' => $signature->minutes?->meeting?->scheduled_at?->toIso8601String(),
            'due_at' => null,
            'is_overdue' => false,
            // Decision wizard — sub-project 2: every meeting duty is taken in MeetingWizard.
            'route' => ['name' => 'meeting_details', 'params' => ['id' => $signature->minutes?->meeting_id], 'query' => ['decide' => 1]],
        ]);
    }

    /**
     * Stage 102 — a proposed meeting date this member has not answered yet.
     * The meeting is not approved until every invited member accepts, so this
     * is the prompt that keeps a sitting from stalling on one silent seat.
     * Ungated by any screen permission: respond() rides `meetings,view` ('*'),
     * and the attendee row itself is the scope.
     *
     * @return array<string, mixed>|null
     */
    private function meetingInvitations(User $actor): ?array
    {
        $rows = Meeting::query()
            ->where('status', Meeting::STATUS_PENDING_CONFIRMATION)
            ->whereHas('attendees', fn (Builder $attendee) => $attendee
                ->where('user_id', $actor->id)
                ->where('invitation_status', 'pending'))
            ->orderBy('scheduled_at')
            ->limit(self::PER_SOURCE_LIMIT + 1)
            ->get(['id', 'title', 'meeting_number', 'scheduled_at']);

        return $this->source('meeting_invitation', $rows, fn (Meeting $meeting) => [
            'title' => $meeting->title,
            'reference_number' => $meeting->meeting_number,
            'subject' => null,
            'waiting_since' => $meeting->scheduled_at?->toIso8601String(),
            'due_at' => $meeting->scheduled_at?->toIso8601String(),
            'is_overdue' => false,
            // Decision wizard — sub-project 2: every meeting duty is taken in MeetingWizard.
            'route' => ['name' => 'meeting_details', 'params' => ['id' => $meeting->id], 'query' => ['decide' => 1]],
        ]);
    }

    /**
     * The committee meets at least once a month, convened by the مقرر (user
     * decision 2026-10-02): an active committee with no live meeting dated this
     * calendar month is the مقرر's to schedule. Only the one active R02 holder
     * gets it, since MeetingController::store() refuses everyone else.
     *
     * @return array<string, mixed>|null
     */
    private function meetingsDue(User $actor): ?array
    {
        if (User::activeRapporteur()?->id !== $actor->id) {
            return null;
        }

        $month = [now()->startOfMonth(), now()->endOfMonth()];
        $rows = Committee::query()
            ->where('is_active', true)
            ->whereDoesntHave('meetings', fn (Builder $meeting) => $meeting
                ->where('status', '!=', 'cancelled')
                ->whereBetween('scheduled_at', $month))
            ->orderBy('name_ar')
            ->limit(self::PER_SOURCE_LIMIT + 1)
            ->get(['id', 'name_ar']);

        return $this->source('meeting_due', $rows, fn (Committee $committee) => [
            'title' => $committee->name_ar,
            'reference_number' => null,
            'subject' => null,
            'waiting_since' => $month[0]->toIso8601String(),
            'due_at' => $month[1]->toIso8601String(),
            'is_overdue' => false,
            'route' => ['name' => 'meetings'],
        ]);
    }

    /**
     * The one source that is the employee's OWN move: a file they filed that
     * has come back to them for completion. Everything else here is work done
     * to somebody else's request.
     *
     * Ungated by any screen permission, deliberately — it is scoped by
     * authorship instead, and every role can be the author of a request.
     *
     * @return array<string, mixed>|null
     */
    private function myCompletions(User $actor): ?array
    {
        $rows = Request::query()
            // Stage 95 — the missing documents are the subject's to supply and
            // the filer's to chase, so the prompt goes to both.
            ->where(fn (Builder $mine) => $mine
                ->where('created_by_user_id', $actor->id)
                ->orWhere('subject_user_id', $actor->id))
            ->whereHas('status', fn (Builder $status) => $status
                ->whereIn('code', ['incomplete', 'completion_required', 'returned']))
            ->with(['status:id,code,name_ar,name_en', 'requestType:id,name_ar,name_en'])
            ->orderBy('submitted_at')
            ->limit(self::PER_SOURCE_LIMIT + 1)
            ->get();

        return $this->source('completion', $rows, fn (Request $r) => [
            'title' => $r->title,
            'reference_number' => $r->trackingNumber(),
            'subject' => $r->status?->name_ar,
            'waiting_since' => $r->submitted_at?->toIso8601String(),
            'due_at' => $r->due_date?->toIso8601String(),
            'is_overdue' => $r->overdue_at !== null,
            'route' => ['name' => 'request_details', 'params' => ['id' => $r->id], 'query' => ['decide' => 1]],
        ]);
    }

    /**
     * My-tasks completeness (2026-09-26) — the steps that move a file along
     * the workflow and are not approvals: the subject's manager's «موافقة
     * وإحالة» and HR's registration, as seeded today.
     *
     * Read through WorkflowService::availableTransitions(), the same preview
     * the request workspace renders its buttons from, so the manager gate,
     * R08's unstick rule, required statuses and type overrides are applied
     * once rather than restated. Exception rows (cancel, return, defer…) are
     * options, not duties; `approve` and `submit` have their own sources.
     *
     * @return array<string, mixed>|null
     */
    private function workflowSteps(User $actor): ?array
    {
        $isStep = fn (WorkflowTransition $rule) => ! $rule->is_exception
            && ! in_array($rule->action, ['approve', 'submit'], true);

        $stageIds = WorkflowTransition::query()
            ->where('is_exception', false)
            ->whereNotIn('action', ['approve', 'submit'])
            ->pluck('from_stage_id');

        return $this->tasks('workflow_step', $this->requestsOfferingAction(
            $actor,
            $this->visibleRequests($actor)->whereIn('current_stage_id', $stageIds),
            $isStep,
        ));
    }

    /**
     * My-tasks completeness (2026-09-26) — an overdue file R08 may escalate.
     * The ministry's own `deadline_expired` row loops onto its own stage, and
     * nothing clears `overdue_at`, so a file there would be a task forever.
     *
     * @return array<string, mixed>|null
     */
    private function overdue(User $actor): ?array
    {
        $rules = WorkflowTransition::query()
            ->where('action', 'deadline_expired')
            ->whereColumn('to_stage_id', '!=', 'from_stage_id');

        if (! $actor->roles()->whereIn('roles.id', (clone $rules)->select('required_role_id'))->exists()) {
            return null;
        }

        return $this->tasks('overdue', $this->requestsOfferingAction(
            $actor,
            $this->visibleRequests($actor)
                ->whereNotNull('overdue_at')
                ->whereIn('current_stage_id', $rules->pluck('from_stage_id')),
            fn (WorkflowTransition $rule) => $rule->action === 'deadline_expired',
        ));
    }

    /**
     * Requests where availableTransitions() offers a rule matching $wanted.
     *
     * ponytail: one availableTransitions() call per pre-filtered row; move the
     * rule into SQL if the inbox ever gets slow.
     *
     * @param  Builder<Request>  $candidates
     * @return Collection<int, array<string, mixed>>
     */
    private function requestsOfferingAction(User $actor, Builder $candidates, callable $wanted): Collection
    {
        return collect($candidates
            ->whereDoesntHave('status', fn (Builder $status) => $status->whereIn('code', self::TERMINAL_STATUSES))
            ->orderBy('id')
            ->lazy(50)
            ->map(fn (Request $r) => [$r, $this->workflow->availableTransitions($r, $actor)->first($wanted)])
            ->filter(fn (array $pair) => $pair[1] !== null)
            ->take(self::PER_SOURCE_LIMIT + 1)
            // Decision wizard — a transition waiting on the actor opens the wizard.
            ->map(fn (array $pair) => $this->requestTask($pair[1]->action, $pair[0], $pair[0]->currentStage?->name_ar, ['decide' => 1]))
            ->all());
    }

    /**
     * My-tasks completeness (2026-09-26) — running a sitting, one step at a
     * time. Each condition is the owning endpoint's own refusals, plus
     * MeetingVisibility, and never a `completed` meeting: CheckMeetingMembership
     * refuses every write on one.
     *
     * @return array<string, mixed>|null
     */
    private function meetingDuties(User $actor): ?array
    {
        $meetings = fn () => $this->meetings->apply(Meeting::query(), $actor)
            ->orderBy('scheduled_at')
            ->limit(self::PER_SOURCE_LIMIT + 1);
        // MeetingRequest::isResolved() as SQL.
        $allResolved = fn (Builder $meeting) => $meeting->whereDoesntHave('agendaItems', fn (Builder $item) => $item
            ->where('item_state', '!=', 'complete')
            ->whereDoesntHave('decision'));
        $tasks = collect();

        if ($actor->hasScreenPermission('meeting_agenda', 'can_approve')) {
            $meetings()
                ->whereIn('status', [Meeting::STATUS_PENDING_CONFIRMATION, 'scheduled'])
                ->whereNull('agenda_adopted_at')
                ->whereHas('agendaItems')
                ->get()
                ->each(fn (Meeting $m) => $tasks->push($this->meetingTask('adopt_agenda', $m)));
        }

        if ($actor->hasScreenPermission('meeting_readiness', 'can_edit')) {
            // The server does not wait for the date; the inbox does, so next
            // month's sitting is not today's work.
            $meetings()
                ->where('status', 'scheduled')
                ->whereNull('convened_at')
                ->where('scheduled_at', '<=', now()->endOfDay())
                ->get()
                ->each(fn (Meeting $m) => $tasks->push($this->meetingTask('convene', $m)));
        }

        if ($actor->hasScreenPermission('decisions', 'can_approve')) {
            // Tie and majority refusals are not SQL; such an item still waits
            // on the chair (a re-vote), so it is listed rather than hidden.
            MeetingRequest::query()
                ->whereIn('item_type', ['employee_request', 'appeal'])
                ->whereDoesntHave('decision')
                ->whereNotNull('study_sequence_completed_at')
                ->whereHas('votes', fn (Builder $vote) => $vote->where('vote', '!=', 'abstain'))
                ->whereHas('meeting', fn (Builder $m) => $this->meetings->apply($m, $actor)
                    ->where('status', 'scheduled')
                    ->whereNotNull('convened_at'))
                ->where(fn (Builder $item) => $item
                    ->where(fn (Builder $request) => $request
                        ->where('item_type', 'employee_request')
                        ->whereHas('request.currentStage', fn (Builder $s) => $s->where('code', 'receive_from_committee')))
                    ->orWhere(fn (Builder $appeal) => $appeal
                        ->where('item_type', 'appeal')
                        ->whereHas('appeal.status', fn (Builder $s) => $s->where('code', 'legal_review'))))
                ->with(['meeting:id,title,meeting_number,scheduled_at', 'request:id,title,reference_number,intake_receipt_number'])
                ->limit(self::PER_SOURCE_LIMIT + 1)
                ->get()
                ->each(fn (MeetingRequest $item) => $tasks->push($this->task('record_decision', $item->id, [
                    'title' => $item->request?->title ?? $item->subject,
                    'reference_number' => $item->request?->trackingNumber(),
                    'subject' => $item->meeting?->title,
                    'waiting_since' => $item->meeting?->scheduled_at?->toIso8601String(),
                    'due_at' => null,
                    'is_overdue' => false,
                    // Decision wizard — sub-project 2: the item's wizard opens straight away.
                    'route' => ['name' => 'meeting_live', 'query' => ['meeting' => $item->meeting_id, 'item' => $item->id, 'decide' => 1]],
                ])));
        }

        if ($actor->hasScreenPermission('meeting_minutes', 'can_add')) {
            $meetings()
                ->where('status', 'scheduled')
                ->where(fn (Builder $due) => $due
                    // Not yet drafted, once every item is settled…
                    ->where(fn (Builder $fresh) => $allResolved($fresh
                        ->whereNotNull('convened_at')
                        ->whereDoesntHave('meetingMinutes')))
                    // …or sent back by the reviewer, which generate() clears.
                    ->orWhereHas('meetingMinutes', fn (Builder $minutes) => $minutes
                        ->where('status', MeetingMinutes::STATUS_DRAFT)
                        ->whereNotNull('review_comment')))
                ->get()
                ->each(fn (Meeting $m) => $tasks->push($this->meetingTask('generate_minutes', $m)));
        }

        if ($actor->hasScreenPermission('meeting_minutes', 'can_approve')) {
            $meetings()
                ->where('status', 'scheduled')
                ->whereHas('meetingMinutes', fn (Builder $minutes) => $minutes
                    ->where('status', MeetingMinutes::STATUS_DRAFT)
                    ->whereNull('review_comment'))
                ->get()
                ->each(fn (Meeting $m) => $tasks->push($this->meetingTask('review_minutes', $m)));
        }

        if ($actor->hasScreenPermission('meetings', 'can_edit')) {
            $allResolved($meetings()
                ->where('status', 'scheduled')
                ->whereNotNull('convened_at')
                ->whereHas('meetingMinutes', fn (Builder $minutes) => $minutes->where('status', MeetingMinutes::STATUS_APPROVED)))
                ->get()
                ->each(fn (Meeting $m) => $tasks->push($this->meetingTask('close_meeting', $m)));
        }

        return $this->tasks('meeting_duty', $tasks);
    }

    /**
     * My-tasks completeness (2026-09-26) — a decided file's remaining life:
     * Art. 103's certification, execution, the two archive records, closure,
     * and the approving body's returns and referrals. Conditions follow each
     * endpoint's refusals (RequestClosureService::refusalReason() for closure,
     * minus the closer's own checklist answers, which are input).
     *
     * Each task opens its act in the request wizard (decision wizard sub-project 3).
     *
     * @return array<string, mixed>|null
     */
    private function postDecision(User $actor): ?array
    {
        $edit = $actor->hasScreenPermission('meeting_outputs', 'can_edit');
        $approve = $actor->hasScreenPermission('meeting_outputs', 'can_approve');
        $add = $actor->hasScreenPermission('meeting_outputs', 'can_add');
        $atStatus = fn (array $codes) => fn (Builder $status) => $status->whereIn('code', $codes);
        $decided = fn ($item) => $item->whereHas('decision');
        $closable = fn () => $this->visibleRequests($actor)
            ->whereHas('status', $atStatus(RequestClosureService::CLOSABLE_STATUSES))
            ->whereNull('closed_at');
        $tasks = collect();

        // Decision wizard — sub-project 3: every one of these is a wizard act
        // on the request, so the task opens that act's slip directly.
        $push = function (string $action, Builder $query) use ($tasks) {
            $query->get()->each(fn (Request $r) => $tasks->push($this->requestTask(
                $action, $r, $r->status?->name_ar, ['decide' => $action],
            )));
        };

        if ($edit) {
            $push('execution_soundness', $this->visibleRequests($actor)
                ->whereHas('currentStage', fn (Builder $s) => $s->where('code', 'final_approval_archiving'))
                ->whereHas('status', $atStatus(['final_approved']))
                ->whereNull('execution_soundness'));
            $push('archive_committee_file', $closable()->whereNull('committee_file_archived_at'));
            $push('resolve_approval_return', $this->visibleRequests($actor)
                ->whereHas('approvalReturns', fn (Builder $r) => $r->whereNull('resolved_at'))
                // A formal resolve would overwrite a suspension's status.
                ->whereDoesntHave('suspensions', fn (Builder $s) => $s->whereNull('resolved_at')));
            // One task per open referral, so the id names the row too.
            $this->visibleRequests($actor)
                ->with(['approvalReferrals' => fn ($referral) => $referral->whereNull('result_outcome')->select('id', 'request_id')])
                ->whereHas('approvalReferrals', fn (Builder $r) => $r->whereNull('result_outcome'))
                ->get()
                ->each(fn (Request $r) => $r->approvalReferrals->each(fn ($referral) => $tasks->push([
                    ...$this->requestTask('record_referral_result', $r, $r->status?->name_ar, route: [
                        'name' => 'request_details',
                        'params' => ['id' => $r->id],
                        'query' => ['decide' => 'record_referral_result', 'target' => $referral->id],
                    ]),
                    'id' => 'record_referral_result:'.$r->id.':'.$referral->id,
                ])));
            // Lifting needs the legal opinion given since the suspension;
            // until then the file sits in R11's legal_review source instead.
            $push('lift_suspension', $this->visibleRequests($actor)
                ->whereHas('suspensions', fn (Builder $s) => $s
                    ->whereNull('resolved_at')
                    ->whereExists(fn ($review) => $review->selectRaw('1')
                        ->from('request_legal_reviews')
                        ->whereColumn('request_legal_reviews.request_id', 'request_suspensions.request_id')
                        ->whereColumn('request_legal_reviews.created_at', '>=', 'request_suspensions.suspended_at'))));
        }

        if ($add) {
            $push('archive_service_file', $closable()
                ->whereNull('service_file_archived_at')
                ->whereHas('meetingRequests', $decided));
        }

        if ($approve) {
            $this->visibleRequests($actor)
                ->whereHas('currentStage', fn (Builder $s) => $s->where('code', 'final_approval_archiving'))
                ->whereHas('status', $atStatus(['in_execution']))
                ->whereNull('executed_at')
                ->whereHas('meetingRequests', $decided)
                ->get()
                ->each(fn (Request $r) => $tasks->push($this->requestTask('execute', $r, $r->status?->name_ar, ['decide' => 'execute'])));

            $push('close', $closable()
                ->whereNotNull('committee_file_archived_at')
                ->where(fn (Builder $service) => $service
                    ->whereNotNull('service_file_archived_at')
                    ->orWhereDoesntHave('meetingRequests', $decided))
                ->whereNotExists(fn ($appeal) => $appeal->selectRaw('1')
                    ->from('appeals')
                    ->whereColumn('appeals.original_request_id', 'requests.id')
                    ->where(fn ($open) => $open
                        ->whereNull('appeals.appeal_status_id')
                        ->orWhereNotIn('appeals.appeal_status_id', AppealStatus::query()->where('code', 'notified_closed')->select('id'))))
                ->whereDoesntHave('specialCases', fn (Builder $case) => $case
                    ->whereNull('resolved_at')
                    ->whereIn('case_kind', array_keys(SpecialCaseRules::CLOSURE_BLOCKING))));
        }

        return $this->tasks('post_decision', $tasks);
    }

    /**
     * My-tasks completeness (2026-09-26) — a correction, conflict, special
     * case or withdrawal recorded on a file and not yet settled. A correction
     * is never offered to whoever recorded it: approveCorrection() refuses them.
     *
     * @return array<string, mixed>|null
     */
    private function openRecords(User $actor): ?array
    {
        if (! $actor->hasScreenPermission('meeting_outputs', 'can_edit')) {
            return null;
        }

        // Decision wizard — sub-project 3: $unsettled is also applied inside
        // with() below, against a relation rather than a query Builder.
        $open = [
            'approve_correction' => ['corrections', fn ($c) => $c
                ->whereNull('approved_at')
                ->where(fn (Builder $by) => $by
                    ->whereNull('recorded_by_user_id')
                    ->orWhere('recorded_by_user_id', '!=', $actor->id))],
            'resolve_document_conflict' => ['documentConflicts', fn ($c) => $c->whereNull('resolved_at')],
            'resolve_special_case' => ['specialCases', fn ($c) => $c->whereNull('resolved_at')],
            'determine_withdrawal' => ['withdrawals', fn ($w) => $w->whereNull('determined_at')],
        ];
        $tasks = collect();

        // Decision wizard — sub-project 3: one task per unsettled row, opening
        // that row's act in the request wizard.
        foreach ($open as $action => [$relation, $unsettled]) {
            $this->visibleRequests($actor)
                ->whereHas($relation, $unsettled)
                ->with([$relation => fn ($rows) => $unsettled($rows)->select('id', 'request_id')->orderBy('id')])
                ->get()
                ->each(fn (Request $r) => $r->{$relation}->each(fn ($row) => $tasks->push([
                    ...$this->requestTask($action, $r, $r->status?->name_ar, ['decide' => $action, 'target' => $row->id]),
                    'id' => $action.':'.$r->id.':'.$row->id,
                ])));
        }

        return $this->tasks('open_record', $tasks);
    }

    /**
     * My-tasks completeness (2026-09-26) — each appeal's next step. Every
     * `appeals,edit` endpoint refuses the appellant, so their own appeal is
     * never offered. Every step, nomination included, opens the appeal's wizard.
     *
     * @return array<string, mixed>|null
     */
    private function appeals(User $actor): ?array
    {
        $mayEdit = $actor->hasScreenPermission('appeals', 'can_edit');
        $mayNominate = Appeal::mayNominate($actor);

        if (! $mayEdit && ! $mayNominate) {
            return null;
        }

        $codes = [
            ...($mayEdit ? [...array_keys(self::APPEAL_NEXT_STEP), 'committee_presentation'] : []),
            ...($mayNominate ? ['legal_review'] : []),
        ];

        $tasks = Appeal::query()
            ->where(fn (Builder $notMine) => $notMine
                ->whereNull('appellant_user_id')
                ->orWhere('appellant_user_id', '!=', $actor->id))
            ->whereHas('status', fn (Builder $status) => $status->whereIn('code', $codes))
            ->with(['status:id,code,name_ar', 'originalRequest', 'committeeAgendaItem.decision'])
            ->orderBy('created_at')
            ->limit(self::PER_SOURCE_LIMIT + 1)
            ->get()
            ->map(function (Appeal $appeal) {
                $code = $appeal->status->code;
                $action = self::APPEAL_NEXT_STEP[$code] ?? match (true) {
                    // Once nominated it waits on the committee, not on anyone here.
                    $code === 'legal_review' && $appeal->committeeAgendaItem === null => 'nominate',
                    $code === 'legal_review' => null,
                    $appeal->outcome_executed_at !== null => 'close',
                    $appeal->committeeAgendaItem?->decision !== null => 'execute_outcome',
                    default => null,
                };

                if ($action === null) {
                    return null;
                }

                return $this->task($action, $appeal->id, [
                    'title' => $appeal->originalRequest?->title,
                    'reference_number' => $appeal->originalRequest?->trackingNumber(),
                    'subject' => $appeal->status->name_ar,
                    'waiting_since' => $appeal->created_at?->toIso8601String(),
                    'due_at' => null,
                    'is_overdue' => false,
                    // Decision wizard — sub-project 3: the appeal's own wizard, on its act.
                    'route' => ['name' => 'appeals', 'query' => ['appeal' => $appeal->id, 'decide' => $action]],
                ]);
            })
            ->filter()
            ->values();

        return $this->tasks('appeal', $tasks);
    }

    /** @return Builder<Request> */
    private function visibleRequests(User $actor): Builder
    {
        return $this->requests->apply(Request::query(), $actor)
            ->with(['status:id,code,name_ar,name_en', 'currentStage:id,code,name_ar,name_en'])
            ->orderBy('submitted_at')
            ->limit(self::PER_SOURCE_LIMIT + 1);
    }

    /** @return array<string, mixed> */
    private function requestTask(string $action, Request $r, ?string $subject, array $query = [], ?array $route = null): array
    {
        return $this->task($action, $r->id, [
            'title' => $r->title,
            'reference_number' => $r->trackingNumber(),
            'subject' => $subject,
            'waiting_since' => $r->submitted_at?->toIso8601String(),
            'due_at' => $r->due_date?->toIso8601String(),
            'is_overdue' => $r->overdue_at !== null,
            'route' => $route ?? array_filter([
                'name' => 'request_details',
                'params' => ['id' => $r->id],
                'query' => $query ?: null,
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function meetingTask(string $action, Meeting $meeting): array
    {
        return $this->task($action, $meeting->id, [
            'title' => $meeting->title,
            'reference_number' => $meeting->meeting_number,
            'subject' => null,
            'waiting_since' => $meeting->scheduled_at?->toIso8601String(),
            'due_at' => $meeting->scheduled_at?->toIso8601String(),
            'is_overdue' => false,
            // Decision wizard — sub-project 2: every meeting duty is taken in MeetingWizard.
            'route' => ['name' => 'meeting_details', 'params' => ['id' => $meeting->id], 'query' => ['decide' => 1]],
        ]);
    }

    /**
     * A task carrying an `action` code, for sources holding several kinds of
     * duty; the screen names it from `myTasks.actions.<code>`.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function task(string $action, int|string $key, array $fields): array
    {
        return ['id' => $action.':'.$key, 'action' => $action, ...$fields];
    }

    /**
     * Shape one source, dropping it entirely when empty so the screen renders
     * only sections that have something in them.
     *
     * @param  Collection<int, mixed>  $rows
     * @return array<string, mixed>|null
     */
    private function source(string $code, Collection $rows, callable $map): ?array
    {
        return $this->tasks($code, $rows->map(fn ($row) => ['id' => (string) $row->getKey(), ...$map($row)]));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $tasks  already shaped, each with an `id`
     * @return array<string, mixed>|null
     */
    private function tasks(string $code, Collection $tasks): ?array
    {
        $truncated = $tasks->count() > self::PER_SOURCE_LIMIT;
        $tasks = $tasks->take(self::PER_SOURCE_LIMIT);

        if ($tasks->isEmpty()) {
            return null;
        }

        $tasks = $tasks->map(fn (array $task) => [...$task, 'id' => $code.':'.$task['id'], 'source' => $code])
            // Overdue first, then longest-waiting — the two orderings anyone
            // actually triages by.
            ->sortBy(fn (array $task) => [$task['is_overdue'] ? 0 : 1, $task['waiting_since'] ?? '9999'])
            ->values()
            ->all();

        return [
            'code' => $code,
            'count' => count($tasks),
            'truncated' => $truncated,
            'tasks' => $tasks,
        ];
    }
}
