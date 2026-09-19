<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Role;
use App\Models\Screen;
use App\Models\User;
use App\Services\MeetingVisibility;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\SitsOnCommittee;
use Tests\TestCase;

/**
 * Stage 28 — the grouped "إدارة الاجتماعات" sidebar section and its screen
 * slots. Everything here is scaffolding (empty routes, seeded permissions),
 * so this only holds the seeding and the API contract the frontend's
 * `navGroups` grouping depends on — not the screen content.
 *
 * Stage 43 pulled `decisions` back out of the group (per [C]: it's a shared
 * system-wide screen, not meetings-only) — `test_decisions_is_shared_not_grouped`
 * covers that.
 *
 * Membership gate — the menu is now also filtered by whether the user holds a
 * committee seat, so the three tests at the bottom pin who sees the group.
 */
class ScreenTest extends TestCase
{
    use RefreshDatabase;
    use SitsOnCommittee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_meetings_management_group_seeds_all_eight_codes(): void
    {
        $codes = [
            'meetings_dashboard', 'committee_candidates', 'meetings',
            'meeting_agenda', 'meeting_readiness', 'meeting_live',
            'meeting_minutes', 'meeting_outputs',
        ];

        $screens = Screen::whereIn('code', $codes)->get()->keyBy('code');

        $this->assertCount(8, $screens);
        foreach ($codes as $code) {
            $this->assertSame('meetings_management', $screens[$code]->group, "code={$code}");
        }

        // A screen outside the group stays ungrouped.
        $this->assertNull(Screen::where('code', 'dashboard')->value('group'));
    }

    public function test_decisions_is_shared_not_grouped(): void
    {
        $this->assertNull(Screen::where('code', 'decisions')->value('group'));
    }

    public function test_a_seated_committee_head_sees_the_group_in_their_screen_menu(): void
    {
        $r03 = $this->userWithRole('R03');
        $this->seatOn(Committee::create(['name_ar' => 'لجنة شؤون الموظفين']), $r03);

        $byCode = $this->screenMenu($r03);

        foreach (MeetingVisibility::GATED_SCREENS as $code) {
            $this->assertTrue($byCode->has($code), "missing {$code}");
        }
        $this->assertSame('meetings_management', $byCode['meetings_dashboard']['group']);
    }

    /**
     * The gate's whole point: no seat, no section. Every gated code disappears
     * from the menu, so AppSidebar — which renders a group only at the position
     * of its first surviving member — never draws the heading at all.
     */
    public function test_an_unseated_user_sees_none_of_the_gated_screens(): void
    {
        $byCode = $this->screenMenu($this->userWithRole('R03'));

        foreach (MeetingVisibility::GATED_SCREENS as $code) {
            $this->assertFalse($byCode->has($code), "leaked {$code}");
        }

        // Screens outside the gate are untouched.
        $this->assertTrue($byCode->has('dashboard'));
        $this->assertTrue($byCode->has('decisions'));
    }

    /**
     * R08 keeps the section with no seat — it is the only role that can repair
     * a committee roster, so a lockout would be unrecoverable through the UI.
     */
    public function test_the_system_administrator_sees_the_group_without_a_seat(): void
    {
        $byCode = $this->screenMenu($this->userWithRole('R08'));

        foreach (MeetingVisibility::GATED_SCREENS as $code) {
            $this->assertTrue($byCode->has($code), "missing {$code}");
        }
    }

    /**
     * meeting_outputs is deliberately outside the gate, because R12 (the
     * executing body) holds no seat. It is kept out of an ordinary employee's
     * menu by a narrowed `view` grant instead.
     */
    public function test_meeting_outputs_is_reachable_without_a_seat_but_not_by_everyone(): void
    {
        $this->assertTrue($this->screenMenu($this->userWithRole('R12'))->has('meeting_outputs'));
        $this->assertFalse($this->screenMenu($this->userWithRole('R01'))->has('meeting_outputs'));
    }

    /** @return Collection<string, array<string, mixed>> */
    private function screenMenu(User $user)
    {
        return collect(
            $this->actingAs($user, 'sanctum')->getJson('/api/screens')->assertOk()->json('data')
        )->keyBy('code');
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
