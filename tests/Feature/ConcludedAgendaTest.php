<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SitsOnCommittee;
use Tests\TestCase;

/**
 * 2026-10-23 — the agenda's adoption went with the in-system sitting, so what
 * fixes an agenda now is its meeting: once the results are in, nothing is
 * added, removed or reordered (CheckMeetingMembership).
 */
class ConcludedAgendaTest extends TestCase
{
    use RefreshDatabase;
    use SitsOnCommittee;

    public function test_the_agenda_of_a_concluded_meeting_is_fixed(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , $meeting] = $this->sitting();
        $item = $this->agendaItem($meeting);
        $candidate = $this->agendaItem($meeting)->request;
        MeetingRequest::query()->where('request_id', $candidate->id)->delete();

        $meeting->update(['status' => 'completed']);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $candidate->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('meeting');

        $this->actingAs($head, 'sanctum')
            ->deleteJson("/api/meetings/{$meeting->id}/agenda/{$item->id}")
            ->assertStatus(422);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/apply-order")
            ->assertStatus(422);

        $this->actingAs($head, 'sanctum')
            ->putJson("/api/meetings/{$meeting->id}/agenda/reorder", ['order' => [$item->id]])
            ->assertStatus(422);

        $this->assertDatabaseHas('meeting_requests', ['id' => $item->id]);
    }

    public function test_the_agenda_has_no_adoption_step(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , $meeting] = $this->sitting();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/adopt")
            ->assertMethodNotAllowed();
    }

    /** @return array{0: User, 1: User, 2: Meeting} */
    private function sitting(): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $this->seatOn($committee, $head, ['is_head' => true]);
        $this->seatOn($committee, $member);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع اللجنة',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id]);
        $meeting->attendees()->create(['user_id' => $member->id]);

        return [$head, $member, $meeting];
    }

    private function agendaItem(Meeting $meeting): MeetingRequest
    {
        $requestRecord = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب ترقية',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $this->userWithRole('R01')->id,
            'submitted_at' => now(),
        ]);

        return $meeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
