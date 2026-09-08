<?php

namespace App\Services;

use App\Models\CommitteeMember;
use App\Models\ConflictOfInterestDeclaration;
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
 * the same conditions as the guard, expressed in SQL.
 *
 * The conditions, in the order the guard reports them:
 *   1. no binding decision recorded yet (voting closes once the head decides),
 *   2. the actor holds a seat on the meeting's committee,
 *   3. the actor is marked as having attended that meeting,
 *   4. the actor has not declared a conflict of interest on this item (Stage 48),
 *   5. the actor is not this meeting's non-voting rapporteur (Stage 48),
 *   6. the item's Art. 85 study sequence is complete (Stage 82).
 *
 * (2) and (3) are separate on purpose: committee membership is standing, but a
 * member who did not attend the sitting does not get a vote on what it decided.
 */
class DecisionEligibility
{
    /**
     * Stage 82 — shared with DecisionController::record(), which re-checks the
     * same condition before it writes a decision rather than trusting that
     * existing votes imply it.
     */
    public const INCOMPLETE_STUDY_SEQUENCE = 'لا يجوز التصويت قبل استكمال تسلسل دراسة البند وإقفال المناقشة (المادة 85).';

    /**
     * Why this user may not vote on this item, or null if they may.
     *
     * Returns the message rather than a boolean because each rule has its own
     * Arabic explanation, and "you can't vote" without the reason reads as a
     * broken button.
     */
    public function reasonBlockingVote(MeetingRequest $agendaItem, User $user): ?string
    {
        // Stage 31 — an admin/emerging item has no request or appeal behind
        // it, so it can never go to a vote. Stage 63 widens this to appeal
        // items, which ride DecisionController::recordAppealDecision()
        // instead of WorkflowService::transition() but still vote the same way.
        if (! in_array($agendaItem->item_type, ['employee_request', 'appeal'], true)) {
            return 'التصويت مقصور على بنود الطلبات أو التظلمات المرتبطة بها.';
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

        if ($this->isRecused($agendaItem, $user)) {
            return 'تم إعلان تعارض مصالح على هذا البند، ولا يجوز لك التصويت عليه.';
        }

        if ($this->isNonVotingRapporteur($agendaItem, $user)) {
            return 'مقرر الاجتماع لا يشارك في التصويت إلا إذا نص قرار تشكيل اللجنة على خلاف ذلك.';
        }

        // Stage 82 — [D] Art. 85 puts إقفال المناقشة *before* التصويت, so a
        // vote cast on an item whose study sequence is incomplete is a vote
        // taken out of the article's own order. The predicate is the
        // denormalised timestamp rather than the JSON beside it precisely so
        // pendingVotesQuery() below can read the same fact in SQL.
        if ($agendaItem->study_sequence_completed_at === null) {
            return self::INCOMPLETE_STUDY_SEQUENCE;
        }

        return null;
    }

    /**
     * Stage 48 — [D] Art. 11/15/18: a disclosed conflict of interest IS the
     * recusal. Shared with MeetingDiscussionNoteController, which blocks the
     * same user from the item's deliberation feed, not only its vote.
     */
    public function isRecused(MeetingRequest $agendaItem, User $user): bool
    {
        return ConflictOfInterestDeclaration::query()
            ->where('meeting_request_id', $agendaItem->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Stage 48 — the meeting's own `rapporteur_user_id` (see Meeting) does not
     * vote unless the committee's tashkil decision granted it
     * (`committees.rapporteur_votes`). A rapporteur may still deliberate and
     * record the discussion — only the vote itself is restricted.
     */
    private function isNonVotingRapporteur(MeetingRequest $agendaItem, User $user): bool
    {
        $meeting = $agendaItem->meeting;

        if ($meeting->rapporteur_user_id !== $user->id) {
            return false;
        }

        return ! ($meeting->committee?->rapporteur_votes ?? false);
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
            // Stage 63 — appeal items vote the same way employee_request
            // ones do; see reasonBlockingVote()'s matching widening.
            ->whereIn('item_type', ['employee_request', 'appeal'])
            ->whereDoesntHave('decision')
            // Stage 48 — the same two exclusions reasonBlockingVote enforces:
            // a declared conflict of interest, or being this meeting's
            // rapporteur without a tashkil-granted vote.
            ->whereDoesntHave('conflictDeclarations', fn (Builder $declaration) => $declaration->where('user_id', $user->id))
            // Stage 82 — the SQL half of reasonBlockingVote()'s Art. 85 check.
            ->whereNotNull('meeting_requests.study_sequence_completed_at')
            ->whereHas('meeting', function (Builder $meeting) use ($user) {
                $meeting
                    ->whereHas('committee.members', fn (Builder $member) => $member->where('user_id', $user->id))
                    ->whereHas('attendees', fn (Builder $attendee) => $attendee
                        ->where('user_id', $user->id)
                        ->where('attended', true))
                    ->where(fn (Builder $rapporteur) => $rapporteur
                        ->where('rapporteur_user_id', '!=', $user->id)
                        ->orWhereNull('rapporteur_user_id')
                        ->orWhereHas('committee', fn (Builder $committee) => $committee->where('rapporteur_votes', true)));
            })
            ->join('meetings', 'meetings.id', '=', 'meeting_requests.meeting_id')
            ->orderBy('meetings.scheduled_at')
            ->orderBy('meeting_requests.agenda_order')
            ->select('meeting_requests.*');
    }
}
