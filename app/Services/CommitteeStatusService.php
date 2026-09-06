<?php

namespace App\Services;

use App\Exceptions\CommitteeStatusTransitionException;
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
 * hardcoded state machine on purpose — nomination, agenda placement, discussion
 * and recommendation-approval are internal committee bookkeeping, not workflow
 * stages, so they must never appear in workflow_transitions or touch
 * current_stage_id / request_stage_logs. Every move is confined to a
 * request currently at the `receive_from_committee` stage, which is the
 * only stage these sub-statuses are meaningful at.
 */
class CommitteeStatusService
{
    /**
     * action => [from statuses, to status, whether a comment is required].
     *
     * `nominate`'s origin list covers both the design doc's "ready" prose and
     * `in_meeting`, the status the existing 6→7 `forward` rule actually sets
     * on arrival at this stage (see WorkflowTransitionSeeder) — the two names
     * describe the same real-world moment.
     */
    private const ACTIONS = [
        'nominate' => [
            'from' => ['ready', 'in_meeting'],
            'to' => 'nominated_for_committee',
            'requires_comment' => false,
        ],
        'place_on_agenda' => [
            'from' => ['nominated_for_committee'],
            'to' => 'on_agenda',
            'requires_comment' => false,
        ],
        'remove_from_agenda' => [
            'from' => ['on_agenda'],
            'to' => 'nominated_for_committee',
            'requires_comment' => true,
        ],
        'start_discussion' => [
            'from' => ['on_agenda'],
            'to' => 'under_discussion',
            'requires_comment' => false,
        ],
        'send_for_recommendation_approval' => [
            'from' => ['under_discussion'],
            'to' => 'awaiting_recommendation_approval',
            'requires_comment' => false,
        ],
        // Stage 32 widened this action's origin beyond the mid-discussion
        // statuses it started with: the candidate-requests worklist offers
        // "request completion" on a not-yet-nominated/nominated item too, so
        // staff can ask for missing material before it ever reaches an
        // agenda, not only once discussion has begun.
        'require_completion' => [
            'from' => ['ready', 'in_meeting', 'nominated_for_committee', 'under_discussion', 'awaiting_recommendation_approval'],
            'to' => 'completion_required',
            'requires_comment' => true,
        ],
        'resume_discussion' => [
            'from' => ['completion_required'],
            'to' => 'under_discussion',
            'requires_comment' => false,
        ],
    ];

    private const COMMITTEE_STAGE_CODE = 'receive_from_committee';

    /**
     * Stage 32 — the committee's "candidate pool": sitting at this stage, not
     * yet placed on any meeting's agenda. Exactly `nominate`'s origin
     * statuses plus the status it moves them to — the same set the
     * candidate-requests worklist and the meetings dashboard's funnel/KPIs
     * both read, so a request the worklist lists is always one the dashboard
     * is already counting.
     */
    public const CANDIDATE_STATUSES = ['ready', 'in_meeting', 'nominated_for_committee'];

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

        return DB::transaction(function () use ($requestRecord, $rule, $actor, $comment) {
            $locked = Request::query()
                ->lockForUpdate()
                ->findOrFail($requestRecord->getKey());

            if ($this->hasTerminalStatus($locked)) {
                throw CommitteeStatusTransitionException::requestClosed();
            }

            if ($locked->currentStage?->code !== self::COMMITTEE_STAGE_CODE) {
                throw CommitteeStatusTransitionException::wrongStage();
            }

            $currentStatusCode = $locked->status?->code;
            if (! in_array($currentStatusCode, $rule['from'], strict: true)) {
                throw CommitteeStatusTransitionException::transitionNotAllowedFromCurrentStatus();
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
    }

    /**
     * @return Builder<Request>
     */
    public function candidatesQuery(): Builder
    {
        return Request::query()
            ->whereHas('currentStage', fn ($query) => $query->where('code', self::COMMITTEE_STAGE_CODE))
            ->whereHas('status', fn ($query) => $query->whereIn('code', self::CANDIDATE_STATUSES));
    }

    private function hasTerminalStatus(Request $requestRecord): bool
    {
        // Stage 64, Track J — decision_withdrawn/decision_amended mirror
        // WorkflowService's own copy of this list: an appeal that finally
        // overturned or amended a decision leaves no further committee
        // sub-status move to make either.
        return $requestRecord->status()
            ->whereIn('code', ['cancelled', 'archived', 'completed_closed', 'decision_withdrawn', 'decision_amended'])
            ->exists();
    }
}
