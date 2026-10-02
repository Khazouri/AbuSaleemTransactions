<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\Meeting;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SitsOnCommittee;
use Tests\TestCase;

/**
 * One مقرر in the system (user decision 2026-10-02): at most one active R02
 * holder, and that person is seated as every committee's rapporteur — so
 * every meeting invites them without anyone seating them by hand.
 */
class SingleRapporteurTest extends TestCase
{
    use RefreshDatabase;
    use SitsOnCommittee;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = $this->userWith('R08');
    }

    public function test_a_second_active_rapporteur_is_refused_on_create_and_update(): void
    {
        $this->userWith('R02');
        $r02 = Role::query()->where('code', 'R02')->value('id');

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/users', [
            'name' => 'مقرر ثانٍ',
            'email' => 'second@example.test',
            'password' => 'password123',
            'role_ids' => [$r02],
        ])->assertStatus(422)->assertJsonValidationErrors('role_ids');

        $other = User::factory()->create();
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/users/{$other->id}", ['role_ids' => [$r02]])
            ->assertStatus(422)->assertJsonValidationErrors('role_ids');

        // An inactive account may hold the role; it is enabling it that clashes.
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/users', [
            'name' => 'مقرر معطل',
            'email' => 'inactive@example.test',
            'password' => 'password123',
            'role_ids' => [$r02],
            'is_active' => false,
        ])->assertCreated();
    }

    public function test_a_deactivated_rapporteur_does_not_block_a_new_one_but_cannot_be_re_enabled(): void
    {
        $former = $this->userWith('R02');
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/users/{$former->id}/toggle-active")->assertOk();

        $successor = User::factory()->create();
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/users/{$successor->id}", ['role_ids' => [Role::query()->where('code', 'R02')->value('id')]])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/users/{$former->id}/toggle-active")
            ->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/users/{$former->id}", ['is_active' => true])
            ->assertStatus(422);
        $this->assertFalse($former->fresh()->is_active);
    }

    // The admin used to form the committee here; only the مقرر may now
    // (2026-10-02, CommitteeIsAGroupTest), so the seat is checked on theirs.
    public function test_a_new_committee_seats_the_rapporteur(): void
    {
        $rapporteur = $this->userWith('R02');

        $committeeId = $this->actingAs($rapporteur, 'sanctum')
            ->postJson('/api/committees', ['name_ar' => 'لجنة شؤون الموظفين'])
            ->assertCreated()->json('data.id');

        $this->assertSame($rapporteur->id, $this->rapporteurSeatHolder($committeeId));
    }

    public function test_scheduling_seats_the_current_rapporteur_and_invites_them(): void
    {
        $former = $this->userWith('R02');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $this->fillFiveSeats($committee, ['rapporteur' => $former]);

        // The role is handed over; the committee still seats the old holder.
        $former->roles()->detach();
        $current = $this->userWith('R02');

        $meetingId = $this->actingAs($current, 'sanctum')->postJson('/api/meetings', [
            'committee_id' => $committee->id,
            'title' => 'الاجتماع الشهري',
            'scheduled_at' => now()->addWeek()->toDateTimeString(),
        ])->assertCreated()->json('data.id');

        $meeting = Meeting::findOrFail($meetingId);
        $this->assertSame($current->id, $meeting->rapporteur_user_id);
        $this->assertSame($current->id, $this->rapporteurSeatHolder($committee->id));
        $this->assertTrue($meeting->attendees()->where('user_id', $current->id)->where('invitation_status', 'confirmed')->exists());
        $this->assertFalse($meeting->attendees()->where('user_id', $former->id)->exists());
    }

    public function test_scheduling_is_refused_while_there_is_no_rapporteur(): void
    {
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/meetings', [
            'committee_id' => $committee->id,
            'title' => 'الاجتماع الشهري',
            'scheduled_at' => now()->addWeek()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors('committee_id');
    }

    public function test_only_the_rapporteur_sets_the_date(): void
    {
        $rapporteur = $this->userWith('R02');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $seats = $this->fillFiveSeats($committee, ['rapporteur' => $rapporteur]);
        $schedule = ['committee_id' => $committee->id, 'title' => 'الاجتماع الشهري', 'scheduled_at' => now()->addWeek()->toDateTimeString()];

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/meetings', $schedule)
            ->assertStatus(422)->assertJsonValidationErrors('scheduled_at');

        $meetingId = $this->actingAs($rapporteur, 'sanctum')->postJson('/api/meetings', $schedule)
            ->assertCreated()->json('data.id');

        $newDate = ['scheduled_at' => now()->addWeek()->addDay()->toDateTimeString()];
        foreach ([$seats['chair'], $this->admin] as $other) {
            $this->actingAs($other, 'sanctum')->putJson("/api/meetings/{$meetingId}", $newDate)
                ->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
        }

        // The chair keeps the rest of the meeting's edits.
        $this->actingAs($seats['chair'], 'sanctum')->putJson("/api/meetings/{$meetingId}", ['title' => 'اجتماع أكتوبر'])->assertOk();
        $this->actingAs($rapporteur, 'sanctum')->putJson("/api/meetings/{$meetingId}", $newDate)->assertOk();
    }

    public function test_the_rapporteur_seat_cannot_be_filled_by_hand(): void
    {
        $rapporteur = $this->userWith('R02');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/committees/{$committee->id}/members", ['user_id' => $rapporteur->id, 'seat' => 'rapporteur'])
            ->assertStatus(422)->assertJsonValidationErrors('seat');
    }

    private function userWith(string $roleCode): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));

        return $user;
    }

    private function rapporteurSeatHolder(int $committeeId): ?int
    {
        return CommitteeMember::query()->where('committee_id', $committeeId)->where('seat', 'rapporteur')->value('user_id');
    }
}
