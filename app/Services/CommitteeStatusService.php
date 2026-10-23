<?php

namespace App\Services;

use App\Exceptions\CommitteeStatusTransitionException;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Guarded, status-only moves for a request sitting with the committee.
 *
 * WorkflowService stays the sole authority for stage changes (workflow_transitions
 * is admin-configurable and drives current_stage_id). The moves here are a fixed,
 * hardcoded state machine on purpose — completion requests and the legal
 * review are internal committee bookkeeping, not workflow
 * stages, so they must never appear in workflow_transitions or touch
 * current_stage_id / request_stage_logs. Every move is confined to a
 * request currently at the `receive_from_committee` stage, which is the
 * only stage these sub-statuses are meaningful at.
 */
class CommitteeStatusService
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    /**
     * action => [from statuses, to status, whether a comment is required].
     *
     * Stage 102 removed `nominate`: picking a request from the pending list
     * onto a meeting's agenda IS the nomination, so a separate status step
     * was a second way to say the same thing. `nominated_for_committee` stays
     * a candidate status for rows that took it before.
     */
    private const ACTIONS = [
        // Stage 32 widened this action's origin beyond the mid-discussion
        // statuses it started with: the candidate-requests worklist offers
        // "request completion" on a not-yet-nominated/nominated item too, so
        // staff can ask for missing material before it ever reaches an
        // agenda, not only once discussion has begun.
        'require_completion' => [
            'from' => [...self::CANDIDATE_STATUSES, 'under_discussion', 'awaiting_recommendation_approval'],
            'to' => 'completion_required',
            'requires_comment' => true,
        ],

        // --- Stage 68: [D] Art. 21's pre-meeting legal review ---------------
        // Art. 38's code 07 (تحت المراجعة القانونية) is status-only for the
        // same reason every action above is: the review happens inside the
        // `receive_from_committee` stage as agenda preparation, between
        // completeness (Art. 20) and the presentation memo (Art. 22), so it
        // is not a workflow stage and must not move current_stage_id.
        //
        // pass_legal_review is called by RequestLegalReviewController inside
        // the same transaction that writes the RequestLegalReview row, so a
        // recorded review and the status it implies cannot disagree. Every
        // recommendation returns the file to the pending list (2026-10-19).
        // Stage 69 — `completion_required` added to the origin list after
        // validating the map against Appendix 5. Its status-05 rule is that a
        // file waiting on missing material "تعاد إلى (تحت فحص الاكتمال) ولا
        // تقفز مباشرة إلى جدول الأعمال"; for a file parked here by
        // require_completion the analogue is re-review, which Art. 21 and [E]
        // stage 08 both spell out ("استكمال أو تصحيح ثم إعادة المراجعة").
        'send_to_legal_review' => [
            'from' => [...self::CANDIDATE_STATUSES, 'completion_required'],
            'to' => 'under_legal_review',
            'requires_comment' => false,
        ],
        // A permitting verdict returns the file to Art. 38's own code 08
        // (جاهزة للعرض) — `ready` here — a candidate status, so the file
        // rejoins the pending list.
        'pass_legal_review' => [
            'from' => ['under_legal_review'],
            'to' => 'ready',
            'requires_comment' => false,
        ],
    ];

    private const COMMITTEE_STAGE_CODE = 'receive_from_committee';

    /**
     * Stage 32 — the committee's "candidate pool": sitting at this stage, not
     * yet placed on any meeting's agenda — the same set the candidate-requests
     * worklist and the meetings dashboard's funnel/KPIs both read, so a request
     * the worklist lists is always one the dashboard is already counting.
     *
     * Stage 102 — this is the committee's pending list, and the only source a
     * meeting's agenda draws requests from (MeetingController::addAgendaItem).
     * `registered` is what the مقرر's approve lands on; `deferred` is a file
     * the committee deferred, which waits for the next monthly meeting; the two
     * `reopened_*` statuses are a file sent back to the committee (an appeal
     * redo, an approving body's substantive return, a reopen, a lifted
     * suspension) — reopenAtStage() lands every one of them here.
     *
     * Stage 68 deliberately did NOT add `under_legal_review` here, preserving
     * that invariant: a file sitting with the legal member is on *their* queue
     * (legalReviewQueueQuery() below, and the `legal_review` screen), not the
     * rapporteur's candidate worklist — which is exactly Appendix 6's RACI
     * split for المراجعة القانونية. MeetingsDashboardMetrics::funnel() gained
     * its own `legal_review` bucket instead, so nothing vanishes from the
     * dashboard while the review is in progress.
     */
    public const CANDIDATE_STATUSES = [
        'registered', 'deferred', 'ready', 'in_meeting', 'nominated_for_committee',
        'reopened_by_appeal', 'reopened_for_representation',
    ];

    /** Stage 68 — Art. 38's code 07: with the legal member right now. */
    public const LEGAL_REVIEW_STATUS = 'under_legal_review';

    /**
     * Decision wizard, sub-project 2 — why this move cannot be made on the
     * file as it stands, or null. move() throws what this returns (re-read
     * under its row lock); RequestController::detailResource() offers a move
     * only when this is null, so the offer and the endpoint agree.
     */
    public function refusal(Request $requestRecord, string $action): ?CommitteeStatusTransitionException
    {
        $rule = self::ACTIONS[$action] ?? null;
        if ($rule === null) {
            return CommitteeStatusTransitionException::actionNotConfigured();
        }

        if ($this->hasTerminalStatus($requestRecord)) {
            return CommitteeStatusTransitionException::requestClosed();
        }

        if ($requestRecord->currentStage?->code !== self::COMMITTEE_STAGE_CODE) {
            return CommitteeStatusTransitionException::wrongStage();
        }

        if (! in_array($requestRecord->status?->code, $rule['from'], strict: true)) {
            return CommitteeStatusTransitionException::transitionNotAllowedFromCurrentStatus();
        }

        return null;
    }

    /**
     * @throws CommitteeStatusTransitionException
     */
    public function move(
        Request $requestRecord,
        string $action,
        User $actor,
        ?string $comment = null,
    ): Request {
        if (! $requestRecord->exists) {
            throw CommitteeStatusTransitionException::requestNotPersisted();
        }

        if (! $actor->exists || ! $actor->is_active) {
            throw CommitteeStatusTransitionException::actorNotActive();
        }

        $action = trim($action);
        $comment = filled($comment) ? trim($comment) : null;

        $rule = self::ACTIONS[$action] ?? null;
        if ($rule === null) {
            throw CommitteeStatusTransitionException::actionNotConfigured();
        }

        if ($rule['requires_comment'] && $comment === null) {
            throw CommitteeStatusTransitionException::commentRequired();
        }

        $moved = DB::transaction(function () use ($requestRecord, $rule, $action, $actor, $comment) {
            $locked = Request::query()
                ->lockForUpdate()
                ->findOrFail($requestRecord->getKey());

            if ($refusal = $this->refusal($locked, $action)) {
                throw $refusal;
            }

            $toStatus = RequestStatus::where('code', $rule['to'])->firstOrFail();
            $fromStatusId = $locked->status_id;

            $locked->status_id = $toStatus->id;
            $locked->save();

            RequestStatusHistory::create([
                'request_id' => $locked->id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $toStatus->id,
                'reason' => $comment,
                'changed_by_user_id' => $actor->id,
                'changed_at' => now(),
            ]);

            return $locked->refresh();
        });

        // Every hand-off to the legal member and every recorded review passes
        // through here, whichever screen started it.
        match ($action) {
            'send_to_legal_review' => $this->notifications->legalReviewRequested($moved, $actor),
            'pass_legal_review' => $this->notifications->legalReviewRecorded($moved, $actor),
            default => null,
        };

        return $moved;
    }

    /**
     * @return Builder<Request>
     */
    public function candidatesQuery(): Builder
    {
        return Request::query()
            ->whereHas('currentStage', fn ($query) => $query->where('code', self::COMMITTEE_STAGE_CODE))
            ->whereHas('status', fn ($query) => $query->whereIn('code', self::CANDIDATE_STATUSES))
            // Stage 102 — a file picked for a meeting leaves the list until
            // that meeting decides it (a deferral returns it at once) or is
            // cancelled.
            ->whereDoesntHave('meetingRequests', self::awaitingItsMeeting(...));
    }

    /**
     * An agenda item whose meeting has neither decided it nor been cancelled:
     * the file is picked for that meeting. CommitteeBoardService counts the
     * same set as «مدرجة في الاجتماع القادم».
     *
     * @param  Builder<MeetingRequest>  $item
     */
    public static function awaitingItsMeeting(Builder $item): void
    {
        $item->whereDoesntHave('decision')
            ->whereHas('meeting', fn ($meeting) => $meeting->whereNotIn('status', ['completed', 'cancelled']));
    }

    /**
     * Stage 68 — the legal member's own queue: files handed to them and not
     * yet reviewed back out. Deliberately narrower than candidatesQuery(),
     * which is the rapporteur's worklist; the two sets are disjoint.
     *
     * @return Builder<Request>
     */
    /**
     * The legal member's own worklist.
     *
     * Two disjoint sources, deliberately, because [D] puts a legal review at
     * two different points in a file's life. Art. 21's pre-meeting review is a
     * status-only substate of `receive_from_committee` (Stage 68). Art. 105's
     * is not: "ويحال الموضوع للمراجعة القانونية" fires **قبل الاعتماد أو
     * التنفيذ**, long past the committee, and the file sits on
     * `execution_suspended` at whatever approval stage the doubt surfaced —
     * so the stage condition above cannot apply to it. Without this second
     * branch the article's own referral would be a sentence in a status
     * history that never reached the person it names.
     */
    public function legalReviewQueueQuery(): Builder
    {
        return Request::query()->where(function (Builder $queue) {
            $queue->where(function (Builder $preMeeting) {
                $preMeeting
                    ->whereHas('currentStage', fn ($query) => $query->where('code', self::COMMITTEE_STAGE_CODE))
                    ->whereHas('status', fn ($query) => $query->where('code', self::LEGAL_REVIEW_STATUS));
            })->orWhereHas(
                'status',
                fn ($query) => $query->where('code', RequestSuspensionService::SUSPENDED_STATUS),
            );
        });
    }

    private function hasTerminalStatus(Request $requestRecord): bool
    {
        // Stage 64, Track J — decision_withdrawn/decision_amended mirror
        // WorkflowService's own copy of this list: an appeal that finally
        // overturned or amended a decision leaves no further committee
        // sub-status move to make either.
        return $requestRecord->status()
            ->whereIn('code', ['cancelled', 'archived', 'not_approved', 'executed', 'completed_closed', 'decision_withdrawn', 'decision_amended'])
            ->exists();
    }
}
