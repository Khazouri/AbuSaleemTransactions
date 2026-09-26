<?php

namespace App\Services;

use App\Models\Appeal;
use App\Models\Decision;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\Request;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use App\Notifications\ActionRequiredNotification;
use App\Notifications\AppealDecidedNotification;
use App\Notifications\DecisionRecordedNotification;
use App\Notifications\FinancialImpactReviewNotification;
use App\Notifications\MeetingInvitationResponseNotification;
use App\Notifications\MeetingMinutesApprovedNotification;
use App\Notifications\MeetingScheduledNotification;
use App\Notifications\RequestCreatedNotification;
use App\Notifications\RequestDelayEscalationNotification;
use App\Notifications\RequestNoticeCopyNotification;
use App\Notifications\RequestNoticeNotification;
use App\Notifications\RequestOverdueNotification;
use App\Notifications\RequestReferenceAssignedNotification;
use App\Notifications\RequestStageChangedNotification;
use App\Notifications\SystemNotification;
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
    public function requestCreated(Request $requestRecord, User $actor): void
    {
        // Stage 101 — Appendix 6 row 1 makes الموارد البشرية «مطلع» at filing.
        // The subject's manager already arrives through actorsForStage(), since
        // the intake auto-hop lands on their own stage.
        $this->send(
            $this->actorsForStage($requestRecord, $requestRecord->current_stage_id, [$actor->id])
                ->concat($this->hrManagers([$actor->id]))
                ->unique('id'),
            new RequestCreatedNotification($requestRecord, $actor->name),
        );
    }

    /**
     * Stage 14 workflow move.
     *
     * The creator is told their request advanced; the next actors are told
     * they have something to do. These are separate event types on purpose —
     * a department head following twenty requests can mute the running
     * commentary without losing the queue that is actually theirs.
     */
    public function stageChanged(
        Request $requestRecord,
        User $actor,
        string $action,
        ?WorkflowStage $fromStage,
        ?WorkflowStage $toStage,
    ): void {
        $owners = $this->ownersOf($requestRecord, [$actor->id]);

        $this->send(
            $owners,
            new RequestStageChangedNotification($requestRecord, $fromStage, $toStage, $action, $actor->name),
        );

        // Whoever already heard about this move must not also get the
        // action prompt — that is the same news twice.
        $excluded = $owners->pluck('id')->push($actor->id)->all();

        $this->send(
            $this->actorsForStage($requestRecord, $requestRecord->current_stage_id, $excluded),
            new ActionRequiredNotification($requestRecord, $toStage),
        );

        // Stage 47 — قسم المرتبات والمزايا holds no seat in workflow_transitions
        // ([D] doesn't name one; see AGENT_NOTES.md), so it can't be picked up
        // by actorsForStage() above. Stage 102 — told when the file arrives on
        // the committee's pending list, the first stop after the study now
        // that `observations` is off the path. Arrival, not a self-loop at the
        // committee stage (defer etc.): a `return_to_study` sends the file
        // back to requirements_check, so its return to the list re-notifies.
        if ($toStage?->code === 'receive_from_committee'
            && $fromStage?->id !== $toStage->id
            && $requestRecord->has_financial_impact) {
            $this->send($this->salariesAndBenefitsDepartment($excluded), new FinancialImpactReviewNotification($requestRecord));
        }
    }

    /** Stage 17 sweep — a breach concerns both the owner and whoever can unblock it. */
    public function requestOverdue(Request $requestRecord): void
    {
        $recipients = $this->ownersOf($requestRecord)
            ->concat($this->actorsForStage($requestRecord, $requestRecord->current_stage_id))
            ->unique('id');

        $this->send($recipients, new RequestOverdueNotification($requestRecord));
    }

    /**
     * Stage 71 — [D] Appendix 38 names an escalation target per delay level,
     * which until now nothing acted on; the bucket only coloured a dot.
     *
     *   أصفر  → the current owner.
     *   أحمر  → مقرر اللجنة + مدير الموارد البشرية.
     *   حرج   → رئيس اللجنة + السلطة المختصة.
     *
     * "The current owner" is Appendix 17's المسؤول الحالي, and this class
     * already resolves it: actorsForStage() reads the very
     * `workflow_transitions` rows WorkflowService enforces, so the escalation
     * and the button that would clear it can never name different people.
     *
     * The other two rungs name bodies, not this app's roles, so the mapping is
     * a documented judgment call (see AGENT_NOTES.md): مقرر اللجنة → R02,
     * whose RoleSeeder name is literally "المقرر"; مدير الموارد البشرية → R05
     * مدير إدارة الشؤون الإدارية, since no HR-director role exists here;
     * رئيس اللجنة → R03, its literal name; and السلطة المختصة → R07 المدير
     * العام / العميد, the local competent authority left after Stage 57
     * deleted the `competent_authority` stage.
     *
     * Escalation is cumulative: red also tells the owner, and حرج also tells
     * everyone red would have — the lower rungs are still the people who can
     * actually move the file, and Appendix 38 raises the alarm rather than
     * handing it over.
     */
    public function delayEscalated(Request $requestRecord, string $level, int $elapsedDays): void
    {
        $roleCodes = match ($level) {
            'red' => ['R02', 'R05'],
            'critical' => ['R02', 'R05', 'R03', 'R07'],
            default => [],
        };

        $recipients = $this->actorsForStage($requestRecord, $requestRecord->current_stage_id)
            ->concat($this->activeUsersWithRoles($roleCodes))
            ->unique('id');

        $this->send($recipients, new RequestDelayEscalationNotification(
            $requestRecord,
            $level,
            $elapsedDays,
            $requestRecord->currentStage,
        ));
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

    /**
     * The مقرر proposed the date, so the مقرر hears every answer to it —
     * a decline is theirs to act on (propose another date). The مقرر is the
     * committee's rapporteur seat, not whoever happened to schedule: that
     * seat is the one bound to R02 and the one that owns the next step.
     */
    public function invitationAnswered(Meeting $meeting, User $member, string $response): void
    {
        $rapporteurId = $meeting->committee?->members()->where('seat', 'rapporteur')->value('user_id');

        $recipients = User::query()
            ->whereKey($rapporteurId ?? 0)
            ->where('is_active', true)
            ->whereKeyNot($member->id)
            ->get();

        $this->send($recipients, new MeetingInvitationResponseNotification($meeting, $member->name, $response));
    }

    /**
     * Stage 21 — the committee's own record of what it decided.
     *
     * Stage 79 narrowed the audience to the committee, and that is [D] Art.
     * 102 rather than tidying: this message carries the vote tally, and the
     * article's exclusion list for a notice to صاحب العلاقة names "مداولات
     * اللجنة" and "كيفية تصويت كل عضو" among the things it must not contain.
     * النموذج 16's own approved wording for the employee has no tally in it
     * either — it says "للأسباب المثبتة في القرار المعتمد" and stops. The
     * employee now hears the result through Art. 101's own notice, in [D]'s
     * words, fired by the status the decision lands the file on; sending both
     * would also have been the same news twice.
     */
    public function decisionRecorded(Request $requestRecord, Decision $decision, Meeting $meeting, User $actor): void
    {
        $memberIds = $meeting->committee?->members()->pluck('user_id') ?? collect();

        $recipients = User::query()
            ->whereIn('id', $memberIds->all())
            ->where('is_active', true)
            ->whereKeyNot($actor->id)
            // Art. 102 keeps the tally away from صاحب العلاقة — the
            // employee the matter concerns (Stage 95), not the filer.
            ->whereKeyNot($requestRecord->subject_user_id ?? 0)
            ->get();

        $this->send($recipients, new DecisionRecordedNotification($requestRecord, $decision));
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
     * Stage 65, Track J — Art. 75 point 6: the appellant hears the final
     * result once the appeal genuinely concludes. See
     * AppealController::close(), the only caller — it decides WHEN an appeal
     * may close; this only decides who hears about it once it has.
     */
    public function appealDecided(Appeal $appeal, User $actor): void
    {
        $this->send($this->appellantOf($appeal, [$actor->id]), new AppealDecidedNotification($appeal));
    }

    /**
     * Stage 79 — [D] Art. 101's notice to صاحب العلاقة.
     *
     * The audience is exactly one person: the employee whose file this is.
     * Art. 101's own preamble is "يتم إشعار **الموظف**", and Art. 102 narrows
     * the content to "المعلومات التي يحتاجها **صاحب العلاقة**" — so this is
     * deliberately the one dispatch method that resolves no roles, no committee
     * and no department, and (Stage 95) the one that resolves صاحب العلاقة
     * ALONE rather than both owners — both articles name the employee the
     * matter concerns, not the clerk who filed on their behalf. The actor is
     * excluded, so an employee acting on their own file is never told what
     * they just did.
     *
     * @param  array{meeting_number: ?string, required_completion: ?string, detail: ?string}  $context
     */
    public function requestNotice(Request $requestRecord, string $moment, array $context = [], ?int $actorId = null, ?string $issuedBy = null): void
    {
        $this->send(
            $this->subjectOf($requestRecord, $actorId === null ? [] : [$actorId]),
            new RequestNoticeNotification($requestRecord, $moment, $context, $issuedBy),
        );

        // Stage 101 — Appendix 6 row 14: الرئيس المباشر مطلع, الموارد البشرية
        // مشارك. A copy naming the moment, never the notice itself (Art. 102).
        // Both this observer-driven send and المقرر's manual issue arrive here,
        // so neither can skip it. The employee is excluded in case they are
        // also their own manager or an HR user.
        $exclude = array_filter([$actorId, $requestRecord->subject_user_id]);
        $manager = $this->subjectsActiveManager($requestRecord);

        $this->send(
            $this->hrManagers($exclude)
                ->when($manager !== null && ! in_array($manager->id, $exclude, true), fn (Collection $all) => $all->push($manager))
                ->unique('id'),
            new RequestNoticeCopyNotification($requestRecord, $moment),
        );
    }

    /**
     * Active holders of R12 — الموارد البشرية, the seat Appendix 6 names as
     * مطلع on a filing and مشارك on a notice.
     *
     * @param  array<int, int>  $excludeUserIds
     * @return Collection<int, User>
     */
    private function hrManagers(array $excludeUserIds = []): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->when($excludeUserIds !== [], fn ($query) => $query->whereKeyNot($excludeUserIds))
            ->whereHas('roles', fn ($query) => $query->where('roles.code', 'R12'))
            ->get();
    }

    /**
     * The قيد allocated this request's رقم إشاري, superseding the intake
     * receipt the submitter is holding. Fired by WorkflowService::transition()
     * on the one move that mints it, so it reaches the submitter exactly once
     * in the request's life.
     *
     * Addressed to the filer and to صاحب العلاقة (Stage 95): it is the
     * filer's own receipt number that stopped being the file's identifier, and
     * the employee the file is about is the one who must quote the new number
     * from here on. Nobody else was ever given either.
     * The actor is excluded through ownersOf(), consistent with every other
     * method here — though in practice the registrar is never the submitter.
     */
    public function referenceAssigned(Request $requestRecord, User $actor): void
    {
        $this->send(
            $this->ownersOf($requestRecord, [$actor->id]),
            new RequestReferenceAssignedNotification($requestRecord),
        );
    }

    /**
     * Stage 95 — صاحب العلاقة, the employee the request is ABOUT.
     *
     * The audience for anything the source addresses to الموظف by name:
     * Art. 101's twelve notices, and Art. 102's exclusion from the tally.
     *
     * @param  array<int, int>  $excludeUserIds
     * @return Collection<int, User>
     */
    private function subjectOf(Request $requestRecord, array $excludeUserIds = []): Collection
    {
        return $this->activeUser($requestRecord->subject_user_id, $excludeUserIds);
    }

    /**
     * Stage 95 — both people a request belongs to: صاحب العلاقة and whoever
     * filed it. The same person on an ordinary self-filed intake, which is
     * every request raised before this stage.
     *
     * Used for the progress news — moved, overdue, renumbered — because
     * either alone is wrong: telling only the subject loses the clerk sight
     * of work they filed, and telling only the filer leaves the employee the
     * file is about hearing nothing at all.
     *
     * @param  array<int, int>  $excludeUserIds
     * @return Collection<int, User>
     */
    private function ownersOf(Request $requestRecord, array $excludeUserIds = []): Collection
    {
        return $this->activeUser($requestRecord->subject_user_id, $excludeUserIds)
            ->concat($this->activeUser($requestRecord->created_by_user_id, $excludeUserIds))
            ->unique('id')
            ->values();
    }

    /**
     * One user as a collection so callers can concat and unique() without
     * null checks. Empty when the id is null or excluded, the account is
     * inactive, or it has since been removed.
     *
     * @param  array<int, int>  $excludeUserIds
     * @return Collection<int, User>
     */
    private function activeUser(?int $userId, array $excludeUserIds = []): Collection
    {
        if ($userId === null || in_array($userId, $excludeUserIds, true)) {
            return collect();
        }

        return User::query()
            ->whereKey($userId)
            ->where('is_active', true)
            ->get();
    }

    /**
     * An appeal's appellant, mirroring activeUser()'s shape for Request —
     * empty when the appellant is the actor, is inactive, or the account has
     * since been removed.
     *
     * @param  array<int, int>  $excludeUserIds
     * @return Collection<int, User>
     */
    private function appellantOf(Appeal $appeal, array $excludeUserIds = []): Collection
    {
        if ($appeal->appellant_user_id === null
            || in_array($appeal->appellant_user_id, $excludeUserIds, true)) {
            return collect();
        }

        return User::query()
            ->whereKey($appeal->appellant_user_id)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Active users who could move this request out of $stageId.
     *
     * Read from workflow_transitions rather than from a hard-coded stage =>
     * role map: the roles that may act are configuration, and this is the same
     * configuration WorkflowService::transition() enforces under its row lock.
     *
     * Exception rules (return, reject, cancel) are excluded — those are escape
     * hatches someone reaches for, not work waiting in a queue, and including
     * them would tell every role holding a cancellation right that they have
     * something to do. A rule with no required_role_id names nobody in
     * particular on its own, EXCEPT when it is manager-gated
     * (requires_submitter_manager) — see below.
     *
     * Diagram-alignment redesign: direct_manager_review's only outbound row is
     * manager-gated with required_role_id null (no fixed role can act there),
     * so without this the role-based branch alone would find nobody and the
     * manager would never hear about work waiting for them. Whenever any
     * outbound row is manager-gated, the creator's active manager is added
     * alongside whatever role-based recipients are also found — mirroring
     * WorkflowService::actorIsCreatorsActiveManager()'s resolution so "who can
     * act next" and "who gets told" can never disagree.
     *
     * @param  array<int, int>  $excludeUserIds
     * @return Collection<int, User>
     */
    private function actorsForStage(Request $requestRecord, ?int $stageId, array $excludeUserIds = []): Collection
    {
        if ($stageId === null) {
            return collect();
        }

        $outboundRules = WorkflowTransition::query()
            ->where('from_stage_id', $stageId)
            ->where('is_exception', false)
            ->where(function ($query) use ($requestRecord) {
                $query->whereNull('request_type_id');

                if ($requestRecord->request_type_id !== null) {
                    $query->orWhere('request_type_id', $requestRecord->request_type_id);
                }
            })
            ->get(['required_role_id', 'requires_submitter_manager']);

        $roleIds = $outboundRules->whereNotNull('required_role_id')->pluck('required_role_id')->unique()->all();

        $recipients = $roleIds === []
            ? collect()
            : User::query()
                ->where('is_active', true)
                ->when($excludeUserIds !== [], fn ($query) => $query->whereKeyNot($excludeUserIds))
                ->whereHas('roles', fn ($query) => $query->whereIn('roles.id', $roleIds))
                ->get();

        if ($outboundRules->contains(fn (WorkflowTransition $rule) => $rule->requires_submitter_manager)) {
            $manager = $this->subjectsActiveManager($requestRecord);

            if ($manager !== null && ! in_array($manager->id, $excludeUserIds, true)) {
                $recipients->push($manager);
            }
        }

        return $recipients->unique('id');
    }

    /**
     * The manager of صاحب العلاقة, resolved the same way
     * WorkflowService::actorIsSubjectsActiveManager() resolves it: a
     * dangling manager_id (never set, or pointing at a since-deactivated or
     * deleted account) must not name anyone, rather than notifying a manager
     * who could no longer act on this anyway.
     *
     * Stage 95 — the subject's, not the filer's. This is the third copy of
     * that rule (the gate itself and RequestVisibility hold the other two)
     * and the one that decides who is TOLD; resolving it from a different
     * person than the gate would prompt a manager the endpoint refuses.
     */
    private function subjectsActiveManager(Request $requestRecord): ?User
    {
        if ($requestRecord->subject_user_id === null) {
            return null;
        }

        $managerId = User::query()->whereKey($requestRecord->subject_user_id)->value('manager_id');

        if ($managerId === null) {
            return null;
        }

        return User::query()->whereKey($managerId)->where('is_active', true)->first();
    }

    /**
     * Stage 71 — active holders of any of the given role codes.
     *
     * Resolved by code rather than by id for the same reason
     * salariesAndBenefitsDepartment() resolves a department by code: the
     * escalation targets are named in [D], and a code keeps the mapping
     * readable at the call site instead of hiding it behind a seeded id.
     *
     * @param  array<int, string>  $roleCodes
     * @return Collection<int, User>
     */
    private function activeUsersWithRoles(array $roleCodes): Collection
    {
        if ($roleCodes === []) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('roles.code', $roleCodes))
            ->get();
    }

    /**
     * Active users in قسم المرتبات والمزايا (Stage 47) — resolved by
     * department code, the same style WorkflowService resolves stages by
     * code, since this department has no role of its own to key off.
     *
     * @param  array<int, int>  $excludeUserIds
     * @return Collection<int, User>
     */
    private function salariesAndBenefitsDepartment(array $excludeUserIds = []): Collection
    {
        $departmentId = Department::query()->where('code', 'SAL')->value('id');

        if ($departmentId === null) {
            return collect();
        }

        return User::query()
            ->where('department_id', $departmentId)
            ->where('is_active', true)
            ->when($excludeUserIds !== [], fn ($query) => $query->whereKeyNot($excludeUserIds))
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
