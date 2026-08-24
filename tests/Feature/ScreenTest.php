<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Screen;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 28 — the grouped "إدارة الاجتماعات" sidebar section and its 9 screen
 * slots. Everything here is scaffolding (empty routes, seeded permissions),
 * so this only holds the seeding and the API contract the frontend's
 * `navGroups` grouping depends on — not the (nonexistent yet) screen content.
 */
class ScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_meetings_management_group_seeds_all_nine_codes(): void
    {
        $codes = [
            'meetings_dashboard', 'committee_candidates', 'meetings',
            'meeting_agenda', 'meeting_readiness', 'meeting_live',
            'decisions', 'meeting_minutes', 'meeting_outputs',
        ];

        $screens = Screen::whereIn('code', $codes)->get()->keyBy('code');

        $this->assertCount(9, $screens);
        foreach ($codes as $code) {
            $this->assertSame('meetings_management', $screens[$code]->group, "code={$code}");
        }

        // A screen outside the group stays ungrouped.
        $this->assertNull(Screen::where('code', 'dashboard')->value('group'));
    }

    public function test_a_committee_head_sees_the_group_in_their_screen_menu(): void
    {
        $r03 = $this->userWithRole('R03');

        $response = $this->actingAs($r03, 'sanctum')
            ->getJson('/api/screens')
            ->assertOk();

        $byCode = collect($response->json('data'))->keyBy('code');

        $newCodes = [
            'meetings_dashboard', 'committee_candidates', 'meeting_agenda',
            'meeting_readiness', 'meeting_live', 'meeting_minutes', 'meeting_outputs',
        ];

        foreach ($newCodes as $code) {
            $this->assertTrue($byCode->has($code), "missing {$code}");
            $this->assertSame('meetings_management', $byCode[$code]['group']);
        }
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
