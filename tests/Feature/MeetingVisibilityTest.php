<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Meeting;
use App\Models\Role;
use App\Models\User;
use App\Services\MeetingVisibility;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SitsOnCommittee;
use Tests\TestCase;

/**
 * Membership gate — the seat rule itself, before anything is wired to it.
 *
 * Covers the predicate in isolation so that when the controllers start calling
 * it, a failure there is a wiring failure and not an argument about what the
 * rule means.
 */
class MeetingVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use SitsOnCommittee;

    private MeetingVisibility $visibility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->visibility = app(MeetingVisibility::class);
    }

    public function test_a_seated_member_reaches_the_section_and_an_unseated_user_does_not(): void
    {
        $committee = $this->committee();
        $member = $this->userWithRole('R04');
        $stranger = $this->userWithRole('R04');

        $this->seatOn($committee, $member);

        $this->assertTrue($this->visibility->sitsOnAnyCommittee($member));
        $this->assertFalse($this->visibility->sitsOnAnyCommittee($stranger));
    }

    /**
     * A member with no named Article-10 seat still counts — most committees in
     * this system are an open R03/R04 headcount, so requiring a seat value
     * would lock out the ordinary case.
     */
    public function test_a_plain_member_row_with_no_named_seat_still_counts(): void
    {
        $committee = $this->committee();
        $member = $this->userWithRole('R04');

        $this->seatOn($committee, $member);

        $this->assertNull($member->committeeMemberships()->value('seat'));
        $this->assertTrue($this->visibility->sitsOnAnyCommittee($member));
    }

    public function test_the_system_administrator_keeps_the_section_without_a_seat(): void
    {
        $admin = $this->userWithRole('R08');
        $committee = $this->committee();
        $meeting = $this->meeting($committee, $admin);

        $this->assertTrue($this->visibility->sitsOnAnyCommittee($admin));
        $this->assertTrue($this->visibility->canView($admin, $meeting));
        $this->assertNull($this->visibility->visibleCommitteeIds($admin));
    }

    public function test_a_deactivated_committee_stops_granting_reach(): void
    {
        $committee = $this->committee();
        $member = $this->userWithRole('R04');
        $this->seatOn($committee, $member);
        $meeting = $this->meeting($committee, $member);

        $this->assertTrue($this->visibility->canView($member, $meeting));

        $committee->update(['is_active' => false]);

        $this->assertFalse($this->visibility->sitsOnAnyCommittee($member));
        $this->assertFalse($this->visibility->canView($member, $meeting));
        $this->assertSame([], $this->visibility->visibleCommitteeIds($member));
    }

    public function test_a_deleted_committee_stops_granting_reach(): void
    {
        $committee = $this->committee();
        $member = $this->userWithRole('R04');
        $this->seatOn($committee, $member);

        $committee->delete();

        $this->assertFalse($this->visibility->sitsOnAnyCommittee($member));
    }

    public function test_an_inactive_user_reaches_nothing(): void
    {
        $committee = $this->committee();
        $member = $this->userWithRole('R04');
        $this->seatOn($committee, $member);
        $meeting = $this->meeting($committee, $member);

        $member->update(['is_active' => false]);

        $this->assertFalse($this->visibility->sitsOnAnyCommittee($member));
        $this->assertFalse($this->visibility->canView($member, $meeting));
        $this->assertSame(0, $this->visibility->apply(Meeting::query(), $member)->count());
    }

    public function test_the_meeting_list_is_scoped_to_the_actors_own_committees(): void
    {
        $mine = $this->committee('لجنة الترقيات');
        $theirs = $this->committee('لجنة التظلمات');
        $member = $this->userWithRole('R04');
        $this->seatOn($mine, $member);

        $visible = $this->meeting($mine, $member);
        $hidden = $this->meeting($theirs, $member);

        $ids = $this->visibility->apply(Meeting::query(), $member)->pluck('id')->all();

        $this->assertSame([$visible->id], $ids);
        $this->assertTrue($this->visibility->canView($member, $visible));
        $this->assertFalse($this->visibility->canView($member, $hidden));
    }

    /**
     * The gate is per-committee, not merely "is on some committee" — the case
     * no screen-level permission can express at all.
     */
    public function test_a_member_of_one_committee_cannot_open_another_committees_meeting(): void
    {
        $a = $this->committee('لجنة أ');
        $b = $this->committee('لجنة ب');
        $memberOfA = $this->userWithRole('R04');
        $this->seatOn($a, $memberOfA);

        $this->assertTrue($this->visibility->sitsOnAnyCommittee($memberOfA));
        $this->assertFalse($this->visibility->canView($memberOfA, $this->meeting($b, $memberOfA)));
        $this->assertFalse($this->visibility->canViewCommittee($memberOfA, $b));
        $this->assertTrue($this->visibility->canViewCommittee($memberOfA, $a));
    }

    public function test_visible_committee_ids_separates_unrestricted_from_seated_on_nothing(): void
    {
        $committee = $this->committee();
        $member = $this->userWithRole('R04');
        $this->seatOn($committee, $member);

        $this->assertSame([$committee->id], $this->visibility->visibleCommitteeIds($member));
        // Null means "no restriction"; an empty array means "reaches nothing".
        $this->assertSame([], $this->visibility->visibleCommitteeIds($this->userWithRole('R04')));
        $this->assertNull($this->visibility->visibleCommitteeIds($this->userWithRole('R08')));
    }

    private function committee(string $name = 'لجنة شؤون الموظفين'): Committee
    {
        return Committee::create(['name_ar' => $name]);
    }

    private function meeting(Committee $committee, User $creator): Meeting
    {
        return Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع '.$committee->name_ar,
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(2),
            'created_by_user_id' => $creator->id,
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
