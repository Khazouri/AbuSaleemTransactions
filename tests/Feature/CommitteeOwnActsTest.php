<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MeetingController;
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
 * Stage 99 — [D] Appendix 6 rows 8–11: what the matrix gives اللجنة
 * collectively and العضو القانوني as a member, rather than the chair alone.
 * Since the paper sitting (2026-10-18) only the agenda's adoption is left of
 * them in the system.
 */
class CommitteeOwnActsTest extends TestCase
{
    use RefreshDatabase;
    use SitsOnCommittee;

    public function test_the_chair_adopts_a_non_empty_agenda_once_and_nobody_else_may(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, $member, $meeting] = $this->sitting();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/adopt")
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن اعتماد جدول أعمال فارغ.');

        $this->agendaItem($meeting);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/adopt")
            ->assertForbidden();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/adopt")
            ->assertOk()
            ->assertJsonPath('data.agenda_adopted_by.id', $head->id);

        $this->assertNotNull($meeting->refresh()->agenda_adopted_at);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/adopt")
            ->assertStatus(422);
    }

    /**
     * Stage 102 removed the `emerging` exception with the type itself: once
     * adopted, nothing is added — not even a request from the pending list.
     */
    public function test_the_adopted_agenda_is_fixed(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , $meeting] = $this->sitting();
        $item = $this->agendaItem($meeting);
        $meeting->update(['agenda_adopted_at' => now()]);
        $candidate = $this->agendaItem($meeting)->request;
        MeetingRequest::query()->where('request_id', $candidate->id)->delete();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $candidate->id])
            ->assertStatus(422)
            ->assertJsonPath('message', MeetingController::AGENDA_ALREADY_ADOPTED);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['item_type' => 'emerging', 'subject' => 'موضوع مستجد'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_type');

        $this->actingAs($head, 'sanctum')
            ->deleteJson("/api/meetings/{$meeting->id}/agenda/{$item->id}")
            ->assertStatus(422);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/apply-order")
            ->assertStatus(422);

        $this->assertDatabaseHas('meeting_requests', ['id' => $item->id]);
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
