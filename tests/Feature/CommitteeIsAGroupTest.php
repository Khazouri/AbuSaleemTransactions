<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\TestUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SitsOnCommittee;
use Tests\TestCase;

/**
 * لجنة شؤون الموظفين is a group the مقرر picks and convenes at least once a
 * month, not a unit of the organisation (user decision 2026-10-02).
 */
class CommitteeIsAGroupTest extends TestCase
{
    use RefreshDatabase;
    use SitsOnCommittee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_committee_is_not_a_department_and_an_old_row_is_retired(): void
    {
        $old = Department::query()->withTrashed()->updateOrCreate(
            ['code' => 'CMT'],
            ['name_ar' => 'لجنة شؤون الموظفين', 'deleted_at' => null],
        );
        $stranded = User::factory()->create(['department_id' => $old->id]);

        $this->seed([DepartmentSeeder::class, TestUserSeeder::class]);

        $this->assertNull(Department::query()->where('code', 'CMT')->first());
        $this->assertSame(
            Department::query()->where('code', 'ABS')->value('id'),
            $stranded->fresh()->department_id,
        );
        $this->assertSame(0, User::query()->where('department_id', $old->id)->count());
    }

    public function test_only_the_rapporteur_forms_a_committee_and_picks_its_members(): void
    {
        $rapporteur = $this->userWith('R02');
        $admin = $this->userWith('R08');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/committees', ['name_ar' => 'لجنة المشرف'])
            ->assertStatus(422);

        $committeeId = $this->actingAs($rapporteur, 'sanctum')
            ->postJson('/api/committees', ['name_ar' => 'لجنة شؤون الموظفين'])
            ->assertCreated()
            ->json('data.id');

        $chair = $this->userWith('R03');
        $this->seatOn(Committee::findOrFail($committeeId), $chair, ['seat' => 'chair', 'is_head' => true]);
        $legal = $this->userWith('R11');

        // The chair holds meetings,edit and sits on the committee, yet may not pick.
        $this->actingAs($chair, 'sanctum')
            ->postJson("/api/committees/{$committeeId}/members", ['user_id' => $legal->id, 'seat' => 'legal'])
            ->assertStatus(422);

        $memberId = $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/committees/{$committeeId}/members", ['user_id' => $legal->id, 'seat' => 'legal'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($chair, 'sanctum')
            ->deleteJson("/api/committees/{$committeeId}/members/{$memberId}")
            ->assertStatus(422);
        $this->actingAs($rapporteur, 'sanctum')
            ->deleteJson("/api/committees/{$committeeId}/members/{$memberId}")
            ->assertNoContent();
    }

    public function test_the_rapporteur_is_reminded_of_a_committee_with_no_meeting_this_month(): void
    {
        $rapporteur = $this->userWith('R02');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        Committee::create(['name_ar' => 'لجنة موقوفة', 'is_active' => false]);
        $seats = $this->fillFiveSeats($committee, ['rapporteur' => $rapporteur]);

        $due = $this->meetingDue($rapporteur);
        $this->assertSame([$committee->name_ar], array_column($due, 'title'));
        $this->assertSame('meetings', $due[0]['route']['name']);
        $this->assertSame([], $this->meetingDue($seats['chair']));

        // Next month's sitting and a cancelled one do not answer for this month.
        $this->meeting($committee, now()->addMonthNoOverflow()->startOfMonth()->addDays(3), 'scheduled');
        $this->meeting($committee, now(), 'cancelled');
        $this->assertCount(1, $this->meetingDue($rapporteur));

        $this->meeting($committee, now(), 'pending_confirmation');
        $this->assertSame([], $this->meetingDue($rapporteur));
    }

    /** @return list<array<string, mixed>> */
    private function meetingDue(User $actor): array
    {
        $sources = collect($this->actingAs($actor, 'sanctum')->getJson('/api/my-tasks')->assertOk()->json('data.sources'));

        return $sources->firstWhere('code', 'meeting_due')['tasks'] ?? [];
    }

    private function meeting(Committee $committee, $at, string $status): void
    {
        Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع',
            'scheduled_at' => $at,
            'status' => $status,
        ]);
    }

    private function userWith(string $roleCode): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));

        return $user;
    }
}
