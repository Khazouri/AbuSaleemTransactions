<?php

namespace App\Services\Tasks;

use App\Models\CommitteeMember;
use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\MeetingRequest;
use App\Models\User;
use App\Services\DecisionEligibility;
use App\Services\DecisionTally;
use App\Services\MeetingReadinessService;
use App\Services\MinutesQualityRules;

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
        private readonly MinutesQualityRules $quality,
        private readonly MeetingReadinessService $readiness,
    ) {}

    // --- Refusals the endpoints call ------------------------------------------

    /** MeetingController::adoptAgenda(). */
    public function adoptRefusal(Meeting $meeting): ?string
    {
        if ($meeting->agenda_adopted_at !== null) {
            return 'تم اعتماد جدول الأعمال بالفعل.';
        }

        if (! $meeting->agendaItems()->exists()) {
            return 'لا يمكن اعتماد جدول أعمال فارغ.';
        }

        return null;
    }

    /**
     * MeetingReadinessController::convene(). Its other refusal — a missing
     * reason for overriding readiness — depends on what is typed, so it stays
     * in the endpoint.
     */
    public function conveneRefusal(Meeting $meeting): ?string
    {
        return $meeting->status === 'scheduled' ? null : 'لا يمكن مباشرة اجتماع ليس في حالة \"مجدول\".';
    }

    /**
     * MeetingMinutesController::review()'s approve path. The reviewer's own
     * answer is input, so the wizard asks with it assumed true and what is
     * left is what the meeting's own data fails.
     *
     * @param  array<string, bool>  $reviewerAnswers
     */
    public function approveMinutesRefusal(Meeting $meeting, MeetingMinutes $minutes, array $reviewerAnswers): ?string
    {
        if ($refusal = $this->quality->refusalReason($meeting, $minutes, $reviewerAnswers)) {
            return $refusal;
        }

        if (! $meeting->attendees()->where('attended', true)->exists()) {
            return 'لا يعتمد المحضر دون حضور مسجل من أعضاء اللجنة يوقعون عليه.';
        }

        return null;
    }

    /** MeetingController::update()'s close branch. */
    public function closeRefusal(Meeting $meeting): ?string
    {
        $unresolved = $meeting->agendaItems()->with('decision')->get()
            ->reject(fn (MeetingRequest $item) => $item->isResolved());

        if ($unresolved->isNotEmpty()) {
            return 'لا يمكن إغلاق الاجتماع قبل استكمال جميع بنود جدول الأعمال (تصويت وقرار، أو إنهاء يدوي للبنود الإدارية).';
        }

        $minutes = $meeting->meetingMinutes()->first();
        if ($minutes === null || $minutes->status !== MeetingMinutes::STATUS_APPROVED) {
            return 'لا يمكن إغلاق الاجتماع قبل اعتماد محضر الاجتماع (إنشاء، مراجعة، وتوقيع الحضور).';
        }

        return null;
    }

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
