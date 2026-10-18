<?php

namespace App\Services\Tasks;

use App\Http\Controllers\Api\RequestController;
use App\Models\CommitteeMember;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\User;
use App\Services\MeetingVisibility;

/**
 * Decision wizard — sub-project 2. What one member may do on a meeting, or on
 * one of its agenda items, right now: the acts open to them, the ones held
 * back and why, and where each of the five Art. 10 (أ) seats stands on the
 * proposed date.
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
    public function __construct(private readonly MeetingVisibility $visibility) {}

    /** The two stages where جهة الاعتماد decides, and the role that decides at each. */
    public const AUTHORITY_STAGES = ['approval_by_authority' => 'R07', 'local_governance_ministry' => 'R06'];

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
     * MeetingResultsController::store() — the paper sitting (user decision
     * 2026-10-18). The مقرر seated on this committee enters what the committee
     * recommended, once the meeting's confirmed date has come.
     */
    public function recordResultsRefusal(Meeting $meeting, User $actor): ?string
    {
        if ($meeting->results_recorded_at !== null) {
            return 'تم إدخال نتائج هذا الاجتماع بالفعل.';
        }

        if ($meeting->status !== 'scheduled') {
            return 'لا تدخل نتائج اجتماع لم يتأكد موعده.';
        }

        if ($meeting->scheduled_at->isFuture()) {
            return 'لا تدخل نتائج الاجتماع قبل موعده.';
        }

        // The seated row, not Committee::seatRapporteur(), which re-seats as a
        // side effect and has no place in a refusal.
        if ($meeting->committee?->members()->where('seat', 'rapporteur')->value('user_id') !== $actor->id) {
            return 'يدخل مقرر اللجنة نتائج الاجتماع.';
        }

        if (! $meeting->agendaItems()->exists()) {
            return 'لا توجد بنود في جدول أعمال هذا الاجتماع.';
        }

        return null;
    }

    /**
     * MeetingResultsController::authorityDecision(). The committee only
     * recommends: العميد decides a dean file, and مكتب العميد (R06) records
     * the minister's paper قرار on a minister file, each on the file this
     * meeting recommended on.
     */
    public function authorityDecisionRefusal(MeetingRequest $item, User $actor): ?string
    {
        $file = $item->request;
        if ($file === null || $item->meeting->results_recorded_at === null
            || ! in_array($item->decision?->outcome, ['approve', 'reject'], true)) {
            return 'لا توجد توصية من هذا الاجتماع بانتظار قرار جهة الاعتماد.';
        }

        $role = self::AUTHORITY_STAGES[$file->currentStage()->value('code')] ?? null;
        if ($role === null) {
            return 'ليس هذا الطلب بانتظار قرار جهة الاعتماد.';
        }

        if (! $actor->hasRole($role)) {
            return $role === 'R07' ? 'يتخذ العميد قرار هذا الطلب.' : 'يثبت مكتب العميد قرار الوزير على هذا الطلب.';
        }

        // Art. 94 — a محضر the authority sent back is re-processed first.
        if ($file->openApprovalReturn()->exists()) {
            return RequestController::APPROVAL_RETURN_BLOCK_MESSAGE;
        }

        // Art. 105 and Appendix 60, as at every approve.
        return RequestController::controlGateRefusal($file, 'approve');
    }

    /**
     * @return array{available: list<array<string, mixed>>, blocked: list<array{action: string, reason: string}>}
     */
    public function forAuthority(MeetingRequest $item, User $actor): array
    {
        $duties = ['available' => [], 'blocked' => []];
        if ($this->visibility->isAuthority($actor)) {
            $this->offer($duties, 'authority_decision', $this->authorityDecisionRefusal($item, $actor), false, [
                'authority' => $item->request?->approving_authority ?? 'dean',
            ]);
        }

        return $duties;
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

    /**
     * @return array{available: list<array<string, mixed>>, blocked: list<array{action: string, reason: string}>, seats: list<array<string, mixed>>}
     */
    public function forMeeting(Meeting $meeting, User $actor): array
    {
        $meeting->loadMissing(['attendees', 'committee.members.user']);
        $open = in_array($meeting->status, [Meeting::STATUS_PENDING_CONFIRMATION, 'scheduled'], true);
        $duties = ['available' => [], 'blocked' => []];

        // Stage 102 — each invited member answers the proposed date themselves.
        if ($meeting->status === Meeting::STATUS_PENDING_CONFIRMATION
            && $meeting->attendees->contains('user_id', $actor->id)) {
            $this->offer($duties, 'respond_accept', null);
            $this->offer($duties, 'respond_decline', null);
        }

        if ($open && $meeting->agenda_adopted_at === null
            && $actor->hasScreenPermission('meeting_agenda', 'can_approve')) {
            $this->offer($duties, 'adopt_agenda', $this->adoptRefusal($meeting));
        }

        // The paper sitting (2026-10-18): the مقرر enters the results once,
        // which concludes the meeting. Offered to the seated مقرر alone.
        if ($meeting->status === 'scheduled' && $meeting->results_recorded_at === null
            && $meeting->committee?->members->contains(fn ($member) => $member->seat === 'rapporteur' && $member->user_id === $actor->id)) {
            $this->offer($duties, 'record_results', $this->recordResultsRefusal($meeting, $actor));
        }

        return [...$duties, 'seats' => $this->seats($meeting, $this->meetingSeatState($meeting))];
    }

    /**
     * Each seat's answer to the proposed date — the one thing the paper
     * sitting asks of a member in the system.
     *
     * @return callable(int): string
     */
    private function meetingSeatState(Meeting $meeting): callable
    {
        $attendees = $meeting->attendees->keyBy('user_id');

        return fn (int $userId): string => match ($attendees->get($userId)?->invitation_status) {
            'confirmed' => 'accepted',
            'declined' => 'declined',
            default => 'pending',
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
