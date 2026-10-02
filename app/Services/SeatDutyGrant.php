<?php

namespace App\Services;

use App\Models\CommitteeMember;
use App\Models\Request;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A stand-in seat holder's temporary meeting duties (user decision 2026-10-02).
 *
 * المقرر may seat someone who does not hold the seat's role — an acting chair
 * who is not R03, say. From the invitation (the meeting_attendees row
 * MeetingController::store() writes for each seat) until every meeting they
 * were invited to has concluded AND nothing it decided is still waiting on an
 * approving body, they act with that role on the meeting-duty screens, and
 * — for the workflow rows — only on the requests of their own live meeting.
 *
 * Computed on every read rather than stored, so there is no grant to expire
 * and none that can outlive its meeting: User::screenPermissions() and
 * WorkflowService both resolve the actor afresh on each API call.
 */
class SeatDutyGrant
{
    /**
     * The duties of a sitting member. Not committee_candidates or legal_review
     * (before the agenda) nor meeting_outputs (execution after the decision):
     * those are a role's standing work, not something a seat lends.
     */
    public const SCREENS = [
        'meetings_dashboard',
        'meetings',
        'meeting_agenda',
        'meeting_readiness',
        'meeting_live',
        'decisions',
        'meeting_minutes',
    ];

    /** A meeting still awaiting its date's acceptance, or still to sit. */
    private const OPEN_MEETING_STATUSES = ['pending_confirmation', 'scheduled'];

    /**
     * Statuses of a decided file still before an approving body: the approval
     * cycle itself, a conditional approval, and `final_approved`, which still
     * waits on R07 before it reaches in_execution.
     *
     * @return list<string>
     */
    public static function pendingApprovalStatuses(): array
    {
        return [...ApprovalReturnService::APPROVAL_CYCLE_STATUSES, 'approved_with_conditions', 'final_approved'];
    }

    /** @return Collection<int, int> role ids lent to $user across every live meeting */
    public function roleIdsFor(User $user): Collection
    {
        return $this->roleIds($this->liveSeats($user));
    }

    /** @return Collection<int, int> role ids lent to $user by live meetings with this request on their agenda */
    public function roleIdsForRequest(User $user, Request $requestRecord): Collection
    {
        return $this->roleIds($this->liveSeats($user)->whereExists(fn (Builder $q) => $q
            ->from('meeting_requests')
            ->whereColumn('meeting_requests.meeting_id', 'meetings.id')
            ->where('meeting_requests.request_id', $requestRecord->id)));
    }

    /**
     * The seats $user sits in on a meeting that still lends them its duties.
     * The rapporteur seat is left out: it stays bound to R02, the inviter
     * (StoreCommitteeMemberRequest), so it never lends anything.
     */
    private function liveSeats(User $user): Builder
    {
        $pendingApprovalIds = DB::table('request_statuses')->whereIn('code', self::pendingApprovalStatuses())->select('id');

        return DB::table('meeting_attendees')
            ->join('meetings', 'meetings.id', '=', 'meeting_attendees.meeting_id')
            ->join('committee_members', function ($join) {
                $join->on('committee_members.committee_id', '=', 'meetings.committee_id')
                    ->on('committee_members.user_id', '=', 'meeting_attendees.user_id');
            })
            ->where('meeting_attendees.user_id', $user->id)
            ->whereIn('committee_members.seat', array_keys(array_diff_key(CommitteeMember::SEAT_ROLES, ['rapporteur' => true])))
            ->where(fn (Builder $q) => $q
                ->whereIn('meetings.status', self::OPEN_MEETING_STATUSES)
                ->orWhere(fn (Builder $q) => $q
                    ->where('meetings.status', 'completed')
                    ->whereExists(fn (Builder $q) => $q
                        ->from('meeting_requests')
                        ->join('requests', 'requests.id', '=', 'meeting_requests.request_id')
                        ->whereColumn('meeting_requests.meeting_id', 'meetings.id')
                        ->whereIn('requests.status_id', $pendingApprovalIds))));
    }

    /** @return Collection<int, int> */
    private function roleIds(Builder $seats): Collection
    {
        $codes = $seats->distinct()->pluck('committee_members.seat')
            ->map(fn (string $seat) => CommitteeMember::SEAT_ROLES[$seat]);

        return $codes->isEmpty() ? collect() : Role::query()->whereIn('code', $codes)->pluck('id');
    }
}
