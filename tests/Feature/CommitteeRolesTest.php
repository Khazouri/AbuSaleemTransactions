<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 103 — «أدوار اللجنة» (user decision 2026-10-04): every account starts
 * as an employee, R08 names the مقرر, and the مقرر names the other four seat
 * holders. Each seat role has one holder, and every committee seats them.
 */
class CommitteeRolesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = $this->userWith('R08');
    }

    public function test_a_new_user_starts_as_an_employee(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/users', [
            'name' => 'موظف جديد',
            'email' => 'new@example.test',
            'password' => 'password123',
        ])->assertCreated()->json('data.id');

        $this->assertSame(['R01'], User::findOrFail($id)->roles()->pluck('code')->all());
    }

    public function test_the_users_screen_cannot_grant_a_seat_role_but_keeps_one_already_held(): void
    {
        $r02 = $this->roleId('R02');
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/users', [
            'name' => 'مقرر',
            'email' => 'r02@example.test',
            'password' => 'password123',
            'role_ids' => [$r02],
        ])->assertStatus(422)->assertJsonValidationErrors('role_ids.0');

        $chair = $this->userWith('R03');
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/users/{$chair->id}", ['role_ids' => [$this->roleId('R03'), $this->roleId('R07')]])
            ->assertOk();
        // Unticking a seat role there changes nothing either.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/users/{$chair->id}", ['role_ids' => [$this->roleId('R07')]])
            ->assertOk();

        $this->assertEqualsCanonicalizing(['R03', 'R07'], $chair->roles()->pluck('code')->all());
    }

    public function test_the_admin_names_the_rapporteur_and_the_old_one_loses_the_role(): void
    {
        $former = $this->userWith('R02');
        $successor = User::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/committee-roles/rapporteur', ['user_id' => $successor->id])
            ->assertOk();

        $this->assertSame($successor->id, User::activeRapporteur()?->id);
        $this->assertFalse($former->roles()->where('code', 'R02')->exists());
    }

    public function test_only_the_admin_names_the_rapporteur_and_only_the_rapporteur_names_the_rest(): void
    {
        $rapporteur = $this->userWith('R02');
        $someone = User::factory()->create();

        $this->actingAs($rapporteur, 'sanctum')
            ->putJson('/api/committee-roles/rapporteur', ['user_id' => $someone->id])
            ->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/committee-roles/chair', ['user_id' => $someone->id])
            ->assertStatus(422);

        foreach (['chair' => 'R03', 'ministry_delegate' => 'R04', 'legal' => 'R11', 'hr_director' => 'R12'] as $seat => $code) {
            $holder = User::factory()->create();
            $this->actingAs($rapporteur, 'sanctum')
                ->putJson("/api/committee-roles/{$seat}", ['user_id' => $holder->id])
                ->assertOk();
            $this->assertTrue($holder->roles()->where('code', $code)->exists());
        }

        $seats = $this->actingAs($rapporteur, 'sanctum')->getJson('/api/committee-roles')
            ->assertOk()->json('data.seats');
        $this->assertSame([true, true, true, true, false], array_column($seats, 'can_assign'));
    }

    public function test_an_inactive_user_or_another_seat_holder_is_refused(): void
    {
        $rapporteur = $this->userWith('R02');
        $inactive = User::factory()->create(['is_active' => false]);
        $legal = $this->userWith('R11');

        foreach ([$inactive, $legal, $rapporteur] as $target) {
            $this->actingAs($rapporteur, 'sanctum')
                ->putJson('/api/committee-roles/chair', ['user_id' => $target->id])
                ->assertStatus(422)->assertJsonValidationErrors('user_id');
        }
    }

    public function test_a_new_committee_seats_every_named_holder(): void
    {
        $rapporteur = $this->userWith('R02');
        $this->userWith('R03');
        $chair = User::factory()->create();
        $this->actingAs($rapporteur, 'sanctum')
            ->putJson('/api/committee-roles/chair', ['user_id' => $chair->id])
            ->assertOk();

        $committeeId = $this->actingAs($rapporteur, 'sanctum')
            ->postJson('/api/committees', ['name_ar' => 'لجنة شؤون الموظفين'])
            ->assertCreated()->json('data.id');

        $seat = CommitteeMember::query()->where('committee_id', $committeeId)->where('seat', 'chair')->sole();
        $this->assertSame($chair->id, $seat->user_id);
        $this->assertTrue($seat->is_head);
        $this->assertSame($rapporteur->id, CommitteeMember::query()
            ->where('committee_id', $committeeId)->where('seat', 'rapporteur')->value('user_id'));
    }

    public function test_with_several_holders_the_lowest_id_is_seated(): void
    {
        $first = $this->userWith('R04');
        $this->userWith('R04');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);

        $committee->seatRoleHolders();

        $this->assertSame($first->id, CommitteeMember::query()
            ->where('committee_id', $committee->id)->where('seat', 'ministry_delegate')->value('user_id'));
    }

    private function userWith(string $roleCode): User
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->roleId($roleCode));

        return $user;
    }

    private function roleId(string $code): int
    {
        return Role::query()->where('code', $code)->value('id');
    }
}
