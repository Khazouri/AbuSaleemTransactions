<?php

namespace App\Services\Tasks;

use App\Http\Controllers\Api\RequestController;
use App\Models\CommitteeMember;
use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\MeetingRequest;
use App\Models\User;
use App\Services\DecisionEligibility;
use App\Services\DecisionTally;
use App\Services\MeetingVisibility;
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
        private readonly MeetingVisibility $visibility,
    ) {}

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
     * MeetingReadinessController::convene(). Its other refusal — a missing
     * reason for overriding readiness — depends on what is typed, so it stays
     * in the endpoint.
     */
    public function conveneRefusal(Meeting $meeting): ?string
    {
        return $meeting->status === 'scheduled' ? null : 'لا يمكن مباشرة اجتماع ليس في حالة "مجدول".';
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

        if ($meeting->agendaItems()->where('item_type', 'appeal')->exists()) {
            return 'لا تعرض التظلمات في الاجتماع الورقي؛ احذف التظلم من جدول الأعمال أولاً.';
        }

        // A sitting begun in the system before the paper path keeps what it
        // already decided; it is not entered a second time.
        if ($meeting->agendaItems()->whereHas('decision')->exists()) {
            return 'في جدول الأعمال بند سجل قراره بالفعل.';
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
                // 2026-10-17 — the grade threshold's pick of جهة الاعتماد; the
                // chair's choice on the decision form is what binds.
                'suggested_authority' => $item->request?->requiresMinistryApproval() ? 'minister' : 'dean',
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
     * @return array{available: list<array<string, mixed>>, blocked: list<array{action: string, reason: string}>, seats: list<array<string, mixed>>}
     */
    public function forMeeting(Meeting $meeting, User $actor): array
    {
        $meeting->loadMissing(['attendees', 'agendaItems.decision', 'meetingMinutes.signatures', 'committee.members.user']);
        $minutes = $meeting->meetingMinutes;
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

        // The paper sitting (2026-10-18) replaces convening, the in-system
        // votes and closing: the مقرر enters the results once, which concludes
        // the meeting. Offered to the seated مقرر alone.
        if ($meeting->status === 'scheduled' && $meeting->results_recorded_at === null
            && $meeting->committee?->members->contains(fn ($member) => $member->seat === 'rapporteur' && $member->user_id === $actor->id)) {
            $this->offer($duties, 'record_results', $this->recordResultsRefusal($meeting, $actor));
        }

        // The minutes' moment, as the inbox reads it: the sitting was opened and
        // every item is settled. Narrower than the endpoint (open item in OPEN_ITEMS.md).
        // F4 (final-review) — offered only with no minutes yet, or a draft the
        // chair sent back (review_comment set): a fresh, unreviewed draft is
        // not yet the rapporteur's to regenerate, only the chair's to review.
        if ($meeting->status === 'scheduled' && $meeting->convened_at !== null
            && $meeting->agendaItems->every(fn (MeetingRequest $item) => $item->isResolved())
            && ($minutes === null || ($minutes->status === MeetingMinutes::STATUS_DRAFT && $minutes->review_comment !== null))
            && $actor->hasScreenPermission('meeting_minutes', 'can_add')) {
            $this->offer($duties, 'generate_minutes', null);
        }

        if ($minutes?->status === MeetingMinutes::STATUS_DRAFT
            && $actor->hasScreenPermission('meeting_minutes', 'can_approve')) {
            $this->offer($duties, 'approve_minutes', $this->approveMinutesRefusal(
                $meeting, $minutes, array_fill_keys(MinutesQualityRules::REVIEWER_CHECKS, true),
            ));
            // Sending back checks no quality control, so it is never held back.
            $this->offer($duties, 'return_minutes', null, true);
        }

        if ($minutes?->status === MeetingMinutes::STATUS_PENDING_SIGNATURES
            && $minutes->signatures->contains(fn ($signature) => $signature->user_id === $actor->id && $signature->signed_at === null)
            && $actor->hasScreenPermission('meeting_minutes', 'can_add')) {
            $this->offer($duties, 'sign_minutes', null);
        }

        return [...$duties, 'seats' => $this->seats($meeting, $this->meetingSeatState($meeting))];
    }

    /**
     * The latest thing the sitting asks of each seat: the signature once the
     * محضر is out for it, attendance once convened, otherwise the date.
     *
     * @return callable(int): string
     */
    private function meetingSeatState(Meeting $meeting): callable
    {
        $attendees = $meeting->attendees->keyBy('user_id');
        $minutes = $meeting->meetingMinutes;
        $signatures = $minutes?->signatures->keyBy('user_id') ?? collect();

        return function (int $userId) use ($meeting, $attendees, $minutes, $signatures): string {
            if ($minutes !== null && $minutes->status !== MeetingMinutes::STATUS_DRAFT) {
                return match (true) {
                    ! $signatures->has($userId) => 'absent',
                    $signatures->get($userId)->signed_at !== null => 'signed',
                    default => 'unsigned',
                };
            }

            if ($meeting->convened_at !== null) {
                return $attendees->get($userId)?->attended ? 'present' : 'absent';
            }

            return match ($attendees->get($userId)?->invitation_status) {
                'confirmed' => 'accepted',
                'declined' => 'declined',
                default => 'pending',
            };
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
