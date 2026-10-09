<?php

namespace Tests;

use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\Meeting;
use App\Models\Role;
use App\Models\User;

/**
 * Membership gate — a seat on a committee, for the many tests whose subject is
 * something else entirely (the vote tally, the محضر, readiness, an agenda's
 * ordering) but whose actor now has to hold a seat before MeetingVisibility
 * will let them reach the meeting at all.
 *
 * Written directly rather than through CommitteeController::addMember(),
 * because the callers are mid-walk through a different story; the endpoint and
 * the gate's own refusals are exercised by MeetingVisibilityTest. Same split,
 * and same reason, as Stage 74's RecordsStructuredDecisions, Stage 78's
 * PassesControlGates and Stage 82's RunsStudySequence.
 */
trait SitsOnCommittee
{
    /**
     * Seat a user on a committee.
     *
     * firstOrCreate rather than create: a fixture that already seated this
     * person (CommitteeMeetingTest and MeetingSchedulingWizardTest both build
     * real rosters) must not end up with two rows for them, which would make
     * any per-member count in that test quietly wrong.
     */
    protected function seatOn(Committee $committee, User $user, array $attributes = []): CommitteeMember
    {
        return CommitteeMember::firstOrCreate(
            ['committee_id' => $committee->id, 'user_id' => $user->id],
            array_merge(['is_head' => false, 'seat' => null], $attributes),
        );
    }

    /** Seat a user on the committee that owns this sitting. */
    protected function seatOnMeeting(Meeting $meeting, User $user, array $attributes = []): CommitteeMember
    {
        return $this->seatOn($meeting->committee, $user, $attributes);
    }

    /**
     * Stage 102 — a meeting can be scheduled only once all five Art. 10 (أ)
     * seats are held, each by the role CommitteeMember::SEAT_ROLES names.
     * Fills every seat not already taken, using $users[seat] when given and a
     * fresh user holding the seat's role otherwise.
     *
     * @param  array<string, User>  $users
     * @return array<string, User> seat => the user holding it
     */
    protected function fillFiveSeats(Committee $committee, array $users = []): array
    {
        $holders = [];
        foreach (CommitteeMember::SEAT_ROLES as $seat => $roleCode) {
            $existing = CommitteeMember::query()->where('committee_id', $committee->id)->where('seat', $seat)->first();
            if ($existing !== null) {
                $holders[$seat] = $existing->user;

                continue;
            }

            $user = $users[$seat] ?? User::factory()->create();
            $roleId = Role::query()->where('code', $roleCode)->value('id');
            if (! $user->roles()->whereKey($roleId)->exists()) {
                $user->roles()->attach($roleId);
            }

            CommitteeMember::updateOrCreate(
                ['committee_id' => $committee->id, 'user_id' => $user->id],
                ['seat' => $seat, 'is_head' => $seat === 'chair'],
            );
            $holders[$seat] = $user;
        }

        return $holders;
    }

    /**
     * Give a user a seat somewhere, for tests that only need them to clear the
     * "does the meetings section exist for you at all" gate and do not care
     * which committee it is.
     */
    protected function giveCommitteeSeat(User $user): CommitteeMember
    {
        $committee = Committee::query()->where('is_active', true)->first()
            ?? Committee::create(['name_ar' => 'لجنة الاختبار', 'is_active' => true]);

        return $this->seatOn($committee, $user);
    }
}
