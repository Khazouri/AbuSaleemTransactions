<?php

namespace App\Services;

use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves who may reach a committee, and who may open one of its meetings.
 *
 * Membership gate — until this existed there was no membership-based
 * visibility anywhere in the meeting stack: MeetingController::index() had no
 * actor scoping at all, and `meetings,view` is seeded `'*'`, so every meeting
 * of every committee was listed to every signed-in user. The three membership
 * checks that did exist (DecisionEligibility, MeetingController::store(),
 * ConflictOfInterestController::store()) were copy-pasted inline and all three
 * are write gates.
 *
 * Deliberately shaped like RequestVisibility: a SQL scope for lists plus a
 * per-row predicate built by running that same scope against one key, so the
 * list and the gate cannot disagree about a row and pagination stays correct.
 *
 * NOT the same question DecisionEligibility asks. That service asks "is this
 * user on THIS meeting's committee AND did they attend the sitting" — Stage 48
 * kept membership and attendance apart on purpose, because a member who missed
 * the sitting keeps their seat but loses their vote. This asks only whether
 * they hold a seat, which is what decides whether they may see the room at all.
 */
class MeetingVisibility
{
    /**
     * The screens hidden from anyone with no committee seat.
     *
     * These are the `meetings_management` group MINUS `meeting_outputs` and
     * `legal_review`, and both exclusions are load-bearing rather than
     * oversights. They are the two screens in that group that are about a
     * REQUEST rather than about running a sitting, one on each side of the
     * committee: Art. 21's legal review happens before a file reaches the
     * committee, and execution/closure after it has left. Their actors are
     * correspondingly not guaranteed to hold a seat — R12 (HR Manager, [F]
     * step 10's executing body) in particular does not — so gating them could
     * revoke reach the role exists for, or stall a mandatory step on nothing
     * worse than a roster mistake. Both have their `view` grant narrowed in
     * ScreenRolePermissionSeeder instead, which keeps an ordinary employee out
     * without stranding the people who do the work.
     *
     * `decisions` is absent for a different reason: it was pulled out of the
     * group deliberately as a shared system-wide register. Its ROWS are scoped
     * rather than the screen hidden.
     *
     * Only `can_view` is ever suppressed. Never widen this to the other flags:
     * RequestVisibility derives both its legal-reviewer and its closer/executor
     * clauses from add/edit/approve grants on `legal_review` and
     * `meeting_outputs`, so zeroing a whole row would silently strip request
     * visibility from R11, R02, R03 and R12.
     */
    public const GATED_SCREENS = [
        'meetings_dashboard',
        'committee_candidates',
        'meetings',
        'meeting_agenda',
        'meeting_readiness',
        'meeting_live',
        'meeting_minutes',
    ];

    /**
     * Is the actor seated on any committee — the question that decides whether
     * the whole «إدارة الاجتماعات» section exists for them?
     */
    public function sitsOnAnyCommittee(User $actor): bool
    {
        if (! $actor->is_active) {
            return false;
        }

        if ($this->isExempt($actor)) {
            return true;
        }

        return CommitteeMember::query()
            ->where('user_id', $actor->id)
            ->whereHas('committee', fn (Builder $committee) => $committee->where('is_active', true))
            ->exists();
    }

    /**
     * Limit a meeting query to sittings of committees the actor sits on.
     *
     * @param  Builder<Meeting>  $query
     * @return Builder<Meeting>
     */
    public function apply(Builder $query, User $actor): Builder
    {
        if (! $actor->is_active) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isExempt($actor)) {
            return $query;
        }

        return $query->whereHas('committee', fn (Builder $committee) => $committee
            ->where('is_active', true)
            ->whereHas('members', fn (Builder $member) => $member->where('user_id', $actor->id)));
    }

    /**
     * May the actor open this one sitting?
     *
     * Runs the list scope against a single key rather than repeating the rule,
     * so a meeting this returns true for is always a meeting apply() would
     * have listed — the property that stops a screen offering a row its own
     * detail endpoint then 404s on.
     */
    public function canView(User $actor, Meeting $meeting): bool
    {
        return $this->apply(Meeting::query()->whereKey($meeting->getKey()), $actor)->exists();
    }

    /**
     * Limit a committee query the same way.
     *
     * @param  Builder<Committee>  $query
     * @return Builder<Committee>
     */
    public function applyToCommittees(Builder $query, User $actor): Builder
    {
        if (! $actor->is_active) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isExempt($actor)) {
            return $query;
        }

        return $query->where('is_active', true)
            ->whereHas('members', fn (Builder $member) => $member->where('user_id', $actor->id));
    }

    public function canViewCommittee(User $actor, Committee $committee): bool
    {
        return $this->applyToCommittees(Committee::query()->whereKey($committee->getKey()), $actor)->exists();
    }

    /**
     * The committee ids the actor may see, or NULL when they are unrestricted.
     *
     * For the consumers that reach a committee through some other root — the
     * decisions register (decision -> agenda item -> meeting -> committee) and
     * the meetings dashboard's aggregates — where a relation-path scope would
     * be a second spelling of the rule above. Null rather than "every id"
     * keeps an unrestricted caller from paying for a list it would not filter
     * on, and makes the unrestricted case impossible to confuse with "seated
     * on nothing", which is an empty array.
     *
     * @return list<int>|null
     */
    public function visibleCommitteeIds(User $actor): ?array
    {
        if ($this->isExempt($actor)) {
            return null;
        }

        if (! $actor->is_active) {
            return [];
        }

        return Committee::query()
            ->where('is_active', true)
            ->whereHas('members', fn (Builder $member) => $member->where('user_id', $actor->id))
            ->pluck('id')
            ->all();
    }

    /**
     * R08 keeps the section with no seat of its own.
     *
     * Not a convenience: R08 is the only role that can edit a committee roster
     * (CommitteeController rides the `meetings` screen, whose add/edit the
     * seeder grants R02/R03 — but adding the FIRST member of a new committee
     * is an administrator's act), so gating it on membership would make a
     * misconfigured roster unrecoverable through the UI. MeetingController
     * ::store() has carried exactly this bypass since Stage 84 for the same
     * reason.
     */
    private function isExempt(User $actor): bool
    {
        return $actor->roles()->where('code', 'R08')->exists();
    }
}
