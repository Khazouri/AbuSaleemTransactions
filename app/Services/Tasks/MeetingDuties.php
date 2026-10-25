<?php

namespace App\Services\Tasks;

use App\Http\Controllers\Api\DecisionController;
use App\Http\Controllers\Api\RequestController;
use App\Models\CommitteeMember;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\RequestStatusHistory;
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

        if (! $this->isSeatedRapporteur($meeting, $actor)) {
            return 'يدخل مقرر اللجنة نتائج الاجتماع.';
        }

        if (! $meeting->agendaItems()->exists()) {
            return 'لا توجد بنود في جدول أعمال هذا الاجتماع.';
        }

        return null;
    }

    /**
     * MeetingResultsController::rollback() — paper-meeting plan, item 3 (user
     * decision 2026-10-25). The مقرر takes the whole result back, but only
     * while every file still stands exactly where the result put it: once
     * جهة الاعتماد (or anyone) has moved one, undoing the result would
     * rewrite a decision someone has already acted on.
     */
    public function rollbackResultsRefusal(Meeting $meeting, User $actor): ?string
    {
        if ($meeting->results_recorded_at === null) {
            return 'لم تُدخل نتائج هذا الاجتماع بعد.';
        }

        if (! $this->isSeatedRapporteur($meeting, $actor)) {
            return 'يتراجع مقرر اللجنة عن نتائج الاجتماع.';
        }

        foreach ($meeting->agendaItems()->with(['decision', 'request', 'appeal.status:id,code'])->get() as $item) {
            if ($this->onLaterAgenda($item)) {
                return 'أُدرج أحد بنود هذا الاجتماع في جدول أعمال اجتماع لاحق، فلا يجوز التراجع.';
            }

            $standsAsLeft = $item->item_type === 'appeal'
                ? $item->appeal?->status?->code === ($item->decision?->outcome === 'defer' ? 'legal_review' : 'committee_presentation')
                : $item->request !== null && $this->committeeResultHistory($item) !== null;
            if (! $standsAsLeft) {
                return 'لا يجوز التراجع بعد أن تحرك أحد الطلبات (قرار جهة الاعتماد أو غيره).';
            }
        }

        return null;
    }

    /**
     * The status row this meeting's result wrote on the item's file, while it
     * is still the file's latest move, or null once anything came after it.
     * The stage log catches a transition (the authority's decision, a return,
     * a cancel); the status row, written with it in one beat, catches a
     * status-only move (an approval return, a suspension, a legal review). The
     * rollback restores the row's from_status.
     */
    public function committeeResultHistory(MeetingRequest $item): ?RequestStatusHistory
    {
        $file = $item->request;
        $log = $file->stageLogs()->with('fromStage:id,code')->latest('id')->first();
        if ($log === null || $log->fromStage?->code !== 'receive_from_committee'
            || $log->action !== (DecisionController::ACTIONS[$item->decision?->outcome] ?? null)) {
            return null;
        }

        $history = $file->statusHistory()->latest('id')->first();

        return $history !== null && $history->changed_at->equalTo($log->acted_at) && $history->to_status_id === $file->status_id
            ? $history
            : null;
    }

    /** A deferred file or appeal picked again, or decided by a later meeting, belongs to that meeting now. */
    private function onLaterAgenda(MeetingRequest $item): bool
    {
        return MeetingRequest::query()
            ->where($item->item_type === 'appeal' ? 'appeal_id' : 'request_id', $item->appeal_id ?? $item->request_id)
            ->where('id', '>', $item->id)
            ->whereHas('meeting', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->exists();
    }

    /**
     * The seated row, not Committee::seatRapporteur(), which re-seats as a
     * side effect and has no place in a refusal.
     */
    private function isSeatedRapporteur(Meeting $meeting, User $actor): bool
    {
        return $meeting->committee?->members()->where('seat', 'rapporteur')->value('user_id') === $actor->id;
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
        $duties = ['available' => [], 'blocked' => []];

        // Stage 102 — each invited member answers the proposed date themselves.
        if ($meeting->status === Meeting::STATUS_PENDING_CONFIRMATION
            && $meeting->attendees->contains('user_id', $actor->id)) {
            $this->offer($duties, 'respond_accept', null);
            $this->offer($duties, 'respond_decline', null);
        }

        // The paper sitting (2026-10-18): the مقرر enters the results once,
        // which concludes the meeting. Offered to the seated مقرر alone.
        if ($meeting->status === 'scheduled' && $meeting->results_recorded_at === null
            && $meeting->committee?->members->contains(fn ($member) => $member->seat === 'rapporteur' && $member->user_id === $actor->id)) {
            $this->offer($duties, 'record_results', $this->recordResultsRefusal($meeting, $actor));
        }

        // 2026-10-25 — and may take them back until جهة الاعتماد acts; held
        // back with the reason once a file has moved on.
        if ($meeting->results_recorded_at !== null && $this->isSeatedRapporteur($meeting, $actor)) {
            $this->offer($duties, 'rollback_results', $this->rollbackResultsRefusal($meeting, $actor));
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
