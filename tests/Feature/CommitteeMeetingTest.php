<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommitteeMeetingTest extends TestCase
{
    use RefreshDatabase;

    public function test_committee_head_can_schedule_a_meeting_that_auto_invites_active_members(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');
        $inactiveMember = $this->userWithRole('R04');
        $inactiveMember->update(['is_active' => false]);

        $committee = Committee::create(['name_ar' => 'لجنة المشتريات']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);
        $committee->members()->create(['user_id' => $inactiveMember->id]);

        $response = $this->actingAs($head, 'sanctum')
            ->postJson('/api/meetings', [
                'committee_id' => $committee->id,
                'title' => 'الاجتماع الدوري الأول',
                'scheduled_at' => now()->addDay()->toDateTimeString(),
                'location' => 'قاعة الاجتماعات',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'الاجتماع الدوري الأول');

        $meetingId = $response->json('data.id');
        $attendeeUserIds = collect($response->json('data.attendees'))->pluck('user.id')->sort()->values()->all();

        // Only the two ACTIVE members were invited; the inactive one was skipped.
        $this->assertSame([$head->id, $member->id], $attendeeUserIds);

        $this->assertDatabaseCount('meeting_attendees', 2);
        $this->assertDatabaseHas('meetings', ['id' => $meetingId, 'committee_id' => $committee->id]);
    }

    public function test_agenda_add_remove_and_reorder_and_attendance_marking(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $committee = Committee::create(['name_ar' => 'لجنة المشتريات']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع المراجعة',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $attendee = $meeting->attendees()->create(['user_id' => $head->id]);

        $requestOne = $this->request('AAA');
        $requestTwo = $this->request('BBB');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestOne->id])
            ->assertCreated()
            ->assertJsonPath('data.agenda_order', 1);

        $itemTwo = $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestTwo->id])
            ->assertCreated()
            ->assertJsonPath('data.agenda_order', 2)
            ->json('data.id');

        // Adding the same request twice is rejected.
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestOne->id])
            ->assertStatus(422);

        // Reorder: put the second item first.
        $orderedIds = $meeting->agendaItems()->orderByDesc('id')->pluck('id')->all();
        $this->actingAs($head, 'sanctum')
            ->putJson("/api/meetings/{$meeting->id}/agenda/reorder", ['order' => $orderedIds])
            ->assertOk()
            ->assertJsonPath('data.0.id', $orderedIds[0])
            ->assertJsonPath('data.0.agenda_order', 1);

        // Remove the (now second) item.
        $remainingItemId = $meeting->agendaItems()->where('request_id', $requestOne->id)->value('id');
        $this->actingAs($head, 'sanctum')
            ->deleteJson("/api/meetings/{$meeting->id}/agenda/{$remainingItemId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('meeting_requests', ['id' => $remainingItemId]);
        $this->assertDatabaseHas('meeting_requests', ['id' => $itemTwo]);

        // Mark attendance.
        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/attendees/{$attendee->id}", ['attended' => true])
            ->assertOk()
            ->assertJsonPath('data.attended', true);
    }

    public function test_committee_cannot_be_deleted_once_it_has_held_a_meeting(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = $this->userWithRole('R08');
        $committee = Committee::create(['name_ar' => 'لجنة تجريبية']);
        Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع سابق',
            'scheduled_at' => now()->subWeek(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/committees/{$committee->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('committees', ['id' => $committee->id]);
    }

    private function request(string $suffix): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y')."-ADM-{$suffix}".fake()->unique()->numberBetween(1000, 9999),
            'title' => "طلب {$suffix}",
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'new')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_municipality')->value('id'),
            'submitted_at' => now(),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
