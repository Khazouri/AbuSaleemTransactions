<?php

namespace App\Services\Tasks;

use App\Models\CommitteeMember;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\User;
use App\Services\DecisionEligibility;
use App\Services\DecisionTally;

/**
 * Decision wizard — sub-project 2. What one member may do on a meeting, or on
 * one of its agenda items, right now: the acts open to them, the ones held
 * back and why, and where each of the five Art. 10 (أ) seats stands.
 *
 * An act is listed only if the member holds its grant (the route's
 * screen.permission) and the sitting is at the moment the act belongs to;
 * outside that moment it is not theirs to take, so it is left out rather than
 * reported. Inside it, "blocked" carries the endpoint's own refusal — the
 * refusal methods here are the ones the endpoints call, so the wizard and the
 * endpoint cannot disagree (sub-project 1's blockReason() rule).
 */
class MeetingDuties
{
    public function __construct(
        private readonly DecisionEligibility $eligibility,
        private readonly DecisionTally $tally,
    ) {}

    /**
     * @return array{available: list<array<string, mixed>>, blocked: list<array{action: string, reason: string}>, seats: list<array<string, mixed>>}
     */
    public function forItem(MeetingRequest $item, User $actor): array
    {
        $item->loadMissing(['decision', 'votes', 'conflictDeclarations', 'meeting.attendees', 'meeting.committee.members.user']);
        $duties = ['available' => [], 'blocked' => []];
        $votable = in_array($item->item_type, ['employee_request', 'appeal'], true) && $item->decision === null;
        $seated = (bool) $item->meeting->committee?->members->contains('user_id', $actor->id);

        // Someone with no seat on this committee has no vote to cast here —
        // "not yours", so it is not reported as blocked either.
        if ($votable && $seated && $actor->hasScreenPermission('decisions', 'can_add')) {
            $this->offer($duties, 'vote', $this->eligibility->reasonBlockingVote($item, $actor), false, [
                'my_vote' => $item->votes->firstWhere('user_id', $actor->id)?->vote,
            ]);
        }

        if ($votable && $actor->hasScreenPermission('decisions', 'can_approve')) {
            $resolved = $this->tally->resolve($item);
            // An appeal's decision must state the committee's reason
            // (DecisionController::recordAppealDecision()).
            $this->offer($duties, 'record_decision', $resolved['refusal'], $item->item_type === 'appeal', [
                'outcome' => $resolved['outcome'],
            ]);
        }

        return [...$duties, 'seats' => $this->seats($item->meeting, $this->itemSeatState($item))];
    }

    /**
     * @param  array{available: list<array<string, mixed>>, blocked: list<array{action: string, reason: string}>}  $duties
     * @param  array<string, mixed>  $extra
     */
    private function offer(array &$duties, string $action, ?string $refusal, bool $requiresComment = false, array $extra = []): void
    {
        if ($refusal === null) {
            $duties['available'][] = ['action' => $action, 'requires_comment' => $requiresComment, ...$extra];
        } else {
            $duties['blocked'][] = ['action' => $action, 'reason' => $refusal];
        }
    }

    /** @return callable(int): string */
    private function itemSeatState(MeetingRequest $item): callable
    {
        $votes = $item->votes->keyBy('user_id');
        $recused = $item->conflictDeclarations->pluck('user_id');
        $attendees = $item->meeting->attendees->keyBy('user_id');

        return fn (int $userId): string => match (true) {
            $recused->contains($userId) => 'recused',
            $votes->has($userId) => 'voted',
            ! $attendees->get($userId)?->attended => 'absent',
            default => 'not_voted',
        };
    }

    /**
     * The five Art. 10 (أ) seats in their fixed order, each with its holder
     * and that holder's state for the act at hand.
     *
     * @param  callable(int): string  $state
     * @return list<array{seat: string, user: array{id: int, name: string}|null, state: string}>
     */
    private function seats(Meeting $meeting, callable $state): array
    {
        $holders = ($meeting->committee?->members ?? collect())->whereNotNull('seat')->keyBy('seat');

        return collect(array_keys(CommitteeMember::SEAT_ROLES))
            ->map(function (string $seat) use ($holders, $state) {
                $member = $holders->get($seat);

                return [
                    'seat' => $seat,
                    'user' => $member?->user?->only(['id', 'name']),
                    'state' => $member === null ? 'vacant' : $state($member->user_id),
                ];
            })
            ->all();
    }
}
