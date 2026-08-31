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

/**
 * Stage 31 — the agenda outgrows a request-only ordered list: admin/
 * emerging items with no request, priority, estimated time, and the
 * agenda-builder screen's stats/grouping endpoint.
 */
class MeetingAgendaBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrative_item_can_be_added_without_a_request(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, , $meeting] = $this->committeeAndMeeting();
        $departmentId = Department::where('code', 'ADM')->value('id');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", [
                'item_type' => 'administrative',
                'subject' => 'مراجعة ميزانية القسم',
                'department_id' => $departmentId,
                'priority' => 'high',
                'estimated_minutes' => 15,
            ])
            ->assertCreated()
            ->assertJsonPath('data.item_type', 'administrative')
            ->assertJsonPath('data.subject', 'مراجعة ميزانية القسم')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.estimated_minutes', 15)
            ->assertJsonPath('data.request', null);

        $this->assertDatabaseHas('meeting_requests', [
            'meeting_id' => $meeting->id,
            'item_type' => 'administrative',
            'request_id' => null,
            'subject' => 'مراجعة ميزانية القسم',
        ]);
    }

    public function test_subject_is_required_for_a_non_request_item(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, , $meeting] = $this->committeeAndMeeting();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['item_type' => 'emerging'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject');
    }

    public function test_agenda_stats_totals_and_groups_by_effective_department(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, , $meeting] = $this->committeeAndMeeting();
        $admId = Department::where('code', 'ADM')->value('id');

        $requestRecord = $this->request('AAA', $admId);
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", [
                'request_id' => $requestRecord->id,
                'priority' => 'medium',
                'estimated_minutes' => 20,
            ])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", [
                'item_type' => 'administrative',
                'subject' => 'بند إداري',
                'department_id' => $admId,
                'priority' => 'low',
                'estimated_minutes' => 10,
            ])
            ->assertCreated();

        $response = $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/stats")
            ->assertOk();

        $this->assertSame(2, $response->json('data.total_items'));
        $this->assertSame(30, $response->json('data.total_estimated_minutes'));
        $this->assertSame(1, $response->json('data.by_priority.medium'));
        $this->assertSame(1, $response->json('data.by_priority.low'));
        $this->assertSame(1, $response->json('data.by_type.employee_request'));
        $this->assertSame(1, $response->json('data.by_type.administrative'));

        $groups = $response->json('data.groups');
        $this->assertSame('department', $response->json('data.group_by'));
        $this->assertCount(1, $groups);
        $this->assertSame($admId, $groups[0]['department']['id']);
        $this->assertCount(2, $groups[0]['items']);
    }

    public function test_agenda_stats_can_group_by_request_type_instead(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, , $meeting] = $this->committeeAndMeeting();
        $admId = Department::where('code', 'ADM')->value('id');

        // A promotion request (the helper's default type)...
        $promotionRequest = $this->request('AAA', $admId);
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $promotionRequest->id])
            ->assertCreated();

        // ...a leave request, a different type...
        $leaveRequest = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب إجازة',
            'department_id' => $admId,
            'request_type_id' => RequestType::where('code', 'LEAV')->value('id'),
            'status_id' => RequestStatus::where('code', 'new')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_municipality')->value('id'),
            'submitted_at' => now(),
        ]);
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $leaveRequest->id])
            ->assertCreated();

        // ...and an admin item, which has no request and therefore no type.
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", [
                'item_type' => 'administrative',
                'subject' => 'بند إداري',
            ])
            ->assertCreated();

        $response = $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/stats?group_by=request_type")
            ->assertOk();

        $this->assertSame('request_type', $response->json('data.group_by'));
        $groups = collect($response->json('data.groups'));
        $this->assertCount(3, $groups);

        $promotionTypeId = RequestType::where('code', 'PROM')->value('id');
        $leaveTypeId = RequestType::where('code', 'LEAV')->value('id');

        $this->assertCount(1, $groups->firstWhere('type.id', $promotionTypeId)['items']);
        $this->assertCount(1, $groups->firstWhere('type.id', $leaveTypeId)['items']);
        $this->assertCount(1, $groups->firstWhere('type', null)['items']);
    }

    public function test_agenda_stats_rejects_an_unknown_group_by(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, , $meeting] = $this->committeeAndMeeting();

        $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/stats?group_by=nonsense")
            ->assertStatus(422);
    }

    public function test_updating_priority_and_estimated_minutes_on_an_existing_item(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, , $meeting] = $this->committeeAndMeeting();
        $requestRecord = $this->request('BBB', Department::where('code', 'ADM')->value('id'));
        $item = $meeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]);

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$item->id}", [
                'priority' => 'high',
                'estimated_minutes' => 45,
            ])
            ->assertOk()
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.estimated_minutes', 45);

        $this->assertDatabaseHas('meeting_requests', [
            'id' => $item->id,
            'priority' => 'high',
            'estimated_minutes' => 45,
        ]);
    }

    public function test_voting_and_deciding_are_rejected_on_a_non_request_item(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, $meeting] = $this->committeeAndMeeting();
        $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);

        $item = $meeting->agendaItems()->create([
            'item_type' => 'administrative',
            'subject' => 'بند إداري',
            'agenda_order' => 1,
        ]);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/votes", ['vote' => 'approve'])
            ->assertStatus(422);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/decision", [])
            ->assertStatus(422);

        $this->assertDatabaseCount('votes', 0);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_pending_votes_worklist_excludes_administrative_items(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, , $meeting] = $this->committeeAndMeeting();
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);

        $requestRecord = $this->requestAtCommitteeStage();
        $requestItem = $meeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]);
        $meeting->agendaItems()->create(['item_type' => 'administrative', 'subject' => 'بند إداري', 'agenda_order' => 2]);

        $response = $this->actingAs($head, 'sanctum')
            ->getJson('/api/decisions/pending')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$requestItem->id], $ids);
    }

    /** @return array{0: User, 1: User, 2: Meeting} */
    private function committeeAndMeeting(): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');

        $committee = Committee::create(['name_ar' => 'لجنة المشتريات']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع بناء جدول الأعمال',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);

        return [$head, $member, $meeting];
    }

    private function request(string $suffix, ?int $departmentId): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y')."-ADM-{$suffix}".fake()->unique()->numberBetween(1000, 9999),
            'title' => "طلب {$suffix}",
            'department_id' => $departmentId,
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'new')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_municipality')->value('id'),
            'submitted_at' => now(),
        ]);
    }

    private function requestAtCommitteeStage(): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب معروض على اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
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
