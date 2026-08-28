<?php

namespace App\Services;

use App\Models\CommitteeMember;
use App\Models\MeetingAttendee;
use App\Models\MeetingRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stage 25 — the one place the "may this person vote on this agenda item?"
 * rules live.
 *
 * Two callers read it, and they must agree: DecisionController::vote() rejects
 * an ineligible vote, and DecisionController::pending() lists the items a user
 * still owes a vote on. A worklist that offers an item the vote endpoint would
 * refuse is worse than no worklist at all, so the query below is deliberately
 * the same three conditions as the guard, expressed in SQL.
 *
 * The conditions, in the order the guard reports them:
 *   1. no binding decision recorded yet (voting closes once the head decides),
 *   2. the actor holds a seat on the meeting's committee,
 *   3. the actor is marked as having attended that meeting.
 *
 * (2) and (3) are separate on purpose: committee membership is standing, but a
 * member who did not attend the sitting does not get a vote on what it decided.
 */
class DecisionEligibility
{
    /**
     * Why this user may not vote on this item, or null if they may.
     *
     * Returns the message rather than a boolean because each rule has its own
     * Arabic explanation, and "you can't vote" without the reason reads as a
     * broken button.
     */
    public function reasonBlockingVote(MeetingRequest $agendaItem, User $user): ?string
    {
        // Stage 31 — an admin/emerging item has no request to run through
        // WorkflowService::transition(), so it can never go to a vote.
        if ($agendaItem->item_type !== 'employee_request') {
            return 'التصويت مقصور على بنود الطلبات المرتبطة بطلب.';
        }

        if ($agendaItem->decision()->exists()) {
            return 'تم تسجيل قرار هذا البند بالفعل، لا يمكن التصويت بعد الآن.';
        }

        $isMember = CommitteeMember::query()
            ->where('committee_id', $agendaItem->meeting->committee_id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            return 'التصويت مقصور على أعضاء هذه اللجنة.';
        }

        $attended = MeetingAttendee::query()
            ->where('meeting_id', $agendaItem->meeting_id)
            ->where('user_id', $user->id)
            ->where('attended', true)
            ->exists();

        if (! $attended) {
            return 'التصويت مقصور على الأعضاء المسجل حضورهم في هذا الاجتماع.';
        }

        return null;
    }

    /**
     * Agenda items this user is entitled to vote on and no one has decided yet.
     *
     * Ordered oldest meeting first: the sitting that already happened is the
     * one holding up a request, so it is the one to clear.
     *
     * @return Builder<MeetingRequest>
     */
    public function pendingVotesQuery(User $user): Builder
    {
        return MeetingRequest::query()
            ->where('item_type', 'employee_request')
            ->whereDoesntHave('decision')
            ->whereHas('meeting', function (Builder $meeting) use ($user) {
                $meeting
                    ->whereHas('committee.members', fn (Builder $member) => $member->where('user_id', $user->id))
                    ->whereHas('attendees', fn (Builder $attendee) => $attendee
                        ->where('user_id', $user->id)
                        ->where('attended', true));
            })
            ->join('meetings', 'meetings.id', '=', 'meeting_requests.meeting_id')
            ->orderBy('meetings.scheduled_at')
            ->orderBy('meeting_requests.agenda_order')
            ->select('meeting_requests.*');
    }
}
