<?php

namespace App\Services;

use App\Models\Decision;
use App\Models\Meeting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use App\Notifications\ActionRequiredNotification;
use App\Notifications\DecisionRecordedNotification;
use App\Notifications\MeetingMinutesApprovedNotification;
use App\Notifications\MeetingScheduledNotification;
use App\Notifications\SystemNotification;
use App\Notifications\TransactionCreatedNotification;
use App\Notifications\TransactionOverdueNotification;
use App\Notifications\TransactionStageChangedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Stage 23 — decides WHO hears about each event.
 *
 * Deliberately separate from the notification classes (which decide what is
 * said) and from NotificationSetting (which decides through which channel).
 * Callers state that something happened; nothing outside this class needs to
 * know the recipient rules, so changing "who gets told" is a one-file change.
 *
 * Two audiences recur:
 *   - the creator, who is following their own request, and
 *   - whoever can act next, resolved from the same workflow_transitions rows
 *     that WorkflowService enforces — so an "act on this" message and the
 *     button that actually works can never come apart.
 */
class NotificationDispatcher
{
    /** Stage 13 intake — tell the people who can pick the work up. */
    public function transactionCreated(Transaction $transaction, User $actor): void
    {
        $this->send(
            $this->actorsForStage($transaction, $transaction->current_stage_id, [$actor->id]),
            new TransactionCreatedNotification($transaction, $actor->name),
        );
    }

    /**
     * Stage 14 workflow move.
     *
     * The creator is told their request advanced; the next actors are told
     * they have something to do. These are separate event types on purpose —
     * a department head following twenty transactions can mute the running
     * commentary without losing the queue that is actually theirs.
     */
    public function stageChanged(
        Transaction $transaction,
        User $actor,
        string $action,
        ?WorkflowStage $fromStage,
        ?WorkflowStage $toStage,
    ): void {
        $creator = $this->creatorOf($transaction, [$actor->id]);

        $this->send(
            $creator,
            new TransactionStageChangedNotification($transaction, $fromStage, $toStage, $action, $actor->name),
        );

        // The creator already heard about this move; sending them the
        // action prompt too would be the same news twice.
        $excluded = $creator->pluck('id')->push($actor->id)->all();

        $this->send(
            $this->actorsForStage($transaction, $transaction->current_stage_id, $excluded),
            new ActionRequiredNotification($transaction, $toStage),
        );
    }

    /** Stage 17 sweep — a breach concerns both the owner and whoever can unblock it. */
    public function transactionOverdue(Transaction $transaction): void
    {
        $recipients = $this->creatorOf($transaction)
            ->concat($this->actorsForStage($transaction, $transaction->current_stage_id))
            ->unique('id');

        $this->send($recipients, new TransactionOverdueNotification($transaction));
    }

    /**
     * Stage 20 — the invitation list is the attendee rows the scheduler just
     * created, not the committee's membership, so someone added by hand later
     * is covered by the same path.
     *
     * @param  Collection<int, int>|array<int, int>  $userIds
     */
    public function meetingScheduled(Meeting $meeting, Collection|array $userIds, User $actor): void
    {
        $recipients = User::query()
            ->whereIn('id', collect($userIds)->all())
            ->where('is_active', true)
            ->whereKeyNot($actor->id)
            ->get();

        $this->send($recipients, new MeetingScheduledNotification($meeting));
    }

    /** Stage 21 — the outcome matters to the requester and to the committee that voted. */
    public function decisionRecorded(Transaction $transaction, Decision $decision, Meeting $meeting, User $actor): void
    {
        $memberIds = $meeting->committee?->members()->pluck('user_id') ?? collect();

        $recipients = $this->creatorOf($transaction, [$actor->id])
            ->concat(
                User::query()
                    ->whereIn('id', $memberIds->all())
                    ->where('is_active', true)
                    ->whereKeyNot($actor->id)
                    ->get(),
            )
            ->unique('id');

        $this->send($recipients, new DecisionRecordedNotification($transaction, $decision));
    }

    /**
     * Stage 36 — the minutes finished their lifecycle. Fired only on the
     * transition into `approved` (see MeetingMinutesController::sign), not
     * on generate/review — those are same-session feedback between two
     * people already looking at the screen together.
     */
    public function minutesApproved(Meeting $meeting, User $actor): void
    {
        $memberIds = $meeting->committee?->members()->pluck('user_id') ?? collect();

        $recipients = User::query()
            ->whereIn('id', $memberIds->all())
            ->where('is_active', true)
            ->whereKeyNot($actor->id)
            ->get();

        $this->send($recipients, new MeetingMinutesApprovedNotification($meeting));
    }

    /**
     * The transaction's creator, as a collection so callers can concat and
     * unique() without null checks. Empty when the creator is the actor, is
     * inactive, or the account has since been removed.
     *
     * @param  array<int, int>  $excludeUserIds
     * @return Collection<int, User>
     */
    private function creatorOf(Transaction $transaction, array $excludeUserIds = []): Collection
    {
        if ($transaction->created_by_user_id === null
            || in_array($transaction->created_by_user_id, $excludeUserIds, true)) {
            return collect();
        }

        return User::query()
            ->whereKey($transaction->created_by_user_id)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Active users who could move this transaction out of $stageId.
     *
     * Read from workflow_transitions rather than from a hard-coded stage =>
     * role map: the roles that may act are configuration, and this is the same
     * configuration WorkflowService::transition() enforces under its row lock.
     *
     * Exception rules (return, reject, cancel) are excluded — those are escape
     * hatches someone reaches for, not work waiting in a queue, and including
     * them would tell every role holding a cancellation right that they have
     * something to do. A rule with no required_role_id names nobody in
     * particular, so it contributes no recipients either.
     *
     * @param  array<int, int>  $excludeUserIds
     * @return Collection<int, User>
     */
    private function actorsForStage(Transaction $transaction, ?int $stageId, array $excludeUserIds = []): Collection
    {
        if ($stageId === null) {
            return collect();
        }

        $roleIds = WorkflowTransition::query()
            ->where('from_stage_id', $stageId)
            ->where('is_exception', false)
            ->where(function ($query) use ($transaction) {
                $query->whereNull('transaction_type_id');

                if ($transaction->transaction_type_id !== null) {
                    $query->orWhere('transaction_type_id', $transaction->transaction_type_id);
                }
            })
            ->whereNotNull('required_role_id')
            ->pluck('required_role_id')
            ->unique()
            ->all();

        if ($roleIds === []) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->when($excludeUserIds !== [], fn ($query) => $query->whereKeyNot($excludeUserIds))
            ->whereHas('roles', fn ($query) => $query->whereIn('roles.id', $roleIds))
            ->get();
    }

    /**
     * Queue one notification for a set of recipients.
     *
     * afterCommit() matters more than it looks: DecisionController runs
     * WorkflowService::transition() inside its own database transaction, so
     * without it a worker could pick up a job describing a decision that the
     * outer transaction had not committed yet — or that ends up rolling back.
     * It is set here rather than on the notifications because "only announce
     * what actually happened" is this class's job, not each message's.
     *
     * @param  Collection<int, User>  $recipients
     */
    private function send(Collection $recipients, SystemNotification $notification): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, $notification->afterCommit());
    }
}
