<?php

namespace App\Services\Tasks;

use App\Http\Controllers\Api\ApprovalController;
use App\Models\MeetingMinutes;
use App\Models\MeetingMinuteSignature;
use App\Models\Request;
use App\Models\User;
use App\Services\CommitteeStatusService;
use App\Services\DecisionEligibility;
use App\Services\MeetingVisibility;
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

    public function __construct(
        private readonly CommitteeStatusService $committeeStatus,
        private readonly DecisionEligibility $decisions,
        private readonly MeetingVisibility $meetings,
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
            $this->myCompletions($actor),
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
            'route' => ['name' => 'request_details', 'params' => ['id' => $r->id]],
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
            'route' => ['name' => 'meeting_live', 'query' => ['meeting' => $item->meeting_id, 'item' => $item->id]],
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
            'route' => ['name' => 'committee_candidates'],
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
            'route' => ['name' => 'legal_review'],
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
            'route' => ['name' => 'meeting_minutes', 'query' => ['meeting' => $signature->minutes?->meeting_id]],
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
            'route' => ['name' => 'request_details', 'params' => ['id' => $r->id]],
        ]);
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
        $truncated = $rows->count() > self::PER_SOURCE_LIMIT;
        $rows = $rows->take(self::PER_SOURCE_LIMIT);

        if ($rows->isEmpty()) {
            return null;
        }

        $tasks = $rows->map(fn ($row) => array_merge(
            ['id' => $code.':'.$row->getKey(), 'source' => $code],
            $map($row),
        ))
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
