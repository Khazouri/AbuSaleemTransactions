<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Decision;
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
use Tests\TestCase;

/**
 * Stage 32 — the candidate-requests worklist and the meetings-unit command
 * dashboard. See the AGENT_NOTES Stage 32 entry for why nominate/
 * request-completion run through CommitteeStatusService while defer/
 * return-to-study run through WorkflowService.
 */
class CommitteeCandidatesDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_committee_stage_candidate_statuses(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $candidate = $this->committeeRequest('in_meeting');
        $earlierStage = $this->requestAt('requirements_check', 'in_review');
        $alreadyApproved = $this->requestAt('approval_by_authority', 'approved');

        $response = $this->actingAs($head, 'sanctum')
            ->getJson('/api/committee-candidates')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($candidate->id));
        $this->assertFalse($ids->contains($earlierStage->id));
        $this->assertFalse($ids->contains($alreadyApproved->id));
    }

    public function test_nominate_moves_a_candidate_into_the_committee_pool(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $requestRecord = $this->committeeRequest('in_meeting');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/committee-candidates/{$requestRecord->id}/nominate")
            ->assertOk()
            ->assertJsonPath('data.status.code', 'nominated_for_committee');
    }

    public function test_index_includes_employee_attachment_count_and_proposed_meeting(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $employee = $this->userWithRole('R01');
        $requestRecord = $this->requestAt('receive_from_committee', 'nominated_for_committee', $employee);

        $requestRecord->attachments()->create([
            'disk' => 'local',
            'path' => 'attachments/test.pdf',
            'original_name' => 'ملف.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'uploaded_by_user_id' => $head->id,
        ]);

        $committee = Committee::create(['name_ar' => 'لجنة اختبار الأعمدة']);
        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع مقترح',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(2),
            'created_by_user_id' => $head->id,
        ]);
        MeetingRequest::create([
            'meeting_id' => $meeting->id,
            'request_id' => $requestRecord->id,
            'agenda_order' => 1,
            'item_type' => 'employee_request',
            'priority' => 'high',
        ]);

        $response = $this->actingAs($head, 'sanctum')
            ->getJson('/api/committee-candidates')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $requestRecord->id);
        $this->assertSame($employee->id, $row['created_by']['id']);
        $this->assertSame(1, $row['attachments_count']);
        $this->assertSame($meeting->id, $row['proposed_meeting']['id']);
        $this->assertSame('high', $row['proposed_meeting']['priority']);
    }

    public function test_a_member_can_nominate_but_not_defer(): void
    {
        $this->seed(DatabaseSeeder::class);

        $member = $this->userWithRole('R04');
        $requestRecord = $this->committeeRequest('in_meeting');

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/committee-candidates/{$requestRecord->id}/nominate")
            ->assertOk();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/committee-candidates/{$requestRecord->id}/defer", ['comment' => 'سبب'])
            ->assertStatus(403);
    }

    public function test_defer_requires_a_comment_and_keeps_the_request_at_the_committee_stage(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $requestRecord = $this->committeeRequest('in_meeting');
        $committeeStageId = $requestRecord->current_stage_id;

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/committee-candidates/{$requestRecord->id}/defer")
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/committee-candidates/{$requestRecord->id}/defer", ['comment' => 'تعارض في المواعيد'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'deferred');

        $this->assertSame($committeeStageId, $requestRecord->refresh()->current_stage_id);
    }

    public function test_return_to_study_sends_the_request_back_to_the_observations_stage(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $requestRecord = $this->committeeRequest('in_meeting');
        $observationsStageId = WorkflowStage::where('code', 'observations')->value('id');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/committee-candidates/{$requestRecord->id}/return-to-study")
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/committee-candidates/{$requestRecord->id}/return-to-study", ['comment' => 'ينقص مرفق مالي'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'returned')
            ->assertJsonPath('data.current_stage.code', 'observations');

        $this->assertSame($observationsStageId, $requestRecord->refresh()->current_stage_id);
    }

    public function test_request_completion_requires_a_comment_and_works_before_nomination(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $requestRecord = $this->committeeRequest('ready');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/committee-candidates/{$requestRecord->id}/request-completion")
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/committee-candidates/{$requestRecord->id}/request-completion", ['comment' => 'ينقص محضر لجنة فرعية'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'completion_required');
    }

    public function test_dashboard_kpis_and_board_count_a_hand_built_fixture(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');

        $this->committeeRequest('in_meeting');
        $this->committeeRequest('nominated_for_committee');
        $onAgenda = $this->requestAt('receive_from_committee', 'on_agenda');
        $decided = $this->requestAt('receive_from_committee', 'decided');
        $this->requestAt('local_governance_ministry', 'awaiting_central_approval');
        $this->requestAt('final_approval_archiving', 'executed');

        // overdue_at isn't mass-assignable (only the SLA sweep sets it in
        // production), so it's set directly here rather than via update().
        $overdue = $this->committeeRequest('in_meeting');
        $overdue->overdue_at = now()->subDay();
        $overdue->save();

        $committee = Committee::create(['name_ar' => 'لجنة اختبار']);
        Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع قادم',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(3),
            'created_by_user_id' => $head->id,
        ]);
        Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع سابق',
            'status' => 'completed',
            'scheduled_at' => now()->subWeek(),
            'created_by_user_id' => $head->id,
        ]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع بند بانتظار القرار',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        MeetingRequest::create([
            'meeting_id' => $meeting->id,
            'request_id' => $onAgenda->id,
            'agenda_order' => 1,
            'item_type' => 'employee_request',
        ]);

        $decidedItem = MeetingRequest::create([
            'meeting_id' => $meeting->id,
            'request_id' => $decided->id,
            'agenda_order' => 2,
            'item_type' => 'employee_request',
        ]);
        Decision::create([
            'meeting_request_id' => $decidedItem->id,
            'outcome' => 'approve',
            'votes_approve_count' => 2,
            'votes_reject_count' => 0,
            'votes_defer_count' => 0,
            'decided_by_user_id' => $head->id,
            'decided_at' => now(),
        ]);

        $response = $this->actingAs($head, 'sanctum')
            ->getJson('/api/meetings/dashboard')
            ->assertOk();

        $this->assertSame(3, $response->json('data.kpis.candidates'));
        // Two future `scheduled` meetings: "اجتماع قادم" (+3 days) and
        // "اجتماع بند بانتظار القرار" (+1 day) — "اجتماع سابق" is `completed`
        // and in the past, so it counts toward meetings_held instead.
        $this->assertSame(2, $response->json('data.kpis.upcoming_meetings'));
        $this->assertSame(1, $response->json('data.kpis.meetings_held'));
        $this->assertSame(1, $response->json('data.kpis.pending_decisions'));
        $this->assertSame(1, $response->json('data.kpis.overdue_committee_items'));
        $this->assertSame(1, $response->json('data.kpis.decisions_this_month'));

        // Stage 81 — [D] Appendix 11's ten buckets replaced Stage 32's own
        // six-bucket funnel here: those buckets were this stage's invention
        // and the appendix is the sourced version of the same question, so
        // this is a legitimate update to what the screen reports rather than
        // a regression fix.
        $this->assertNull($response->json('data.funnel'));
        $board = collect($response->json('data.board'))->keyBy('key');

        // The seventh bucket split in two, exactly as the appendix writes it.
        $this->assertCount(11, $board);
        $this->assertSame(1, $board['ready']['total']);       // nominated_for_committee
        $this->assertSame(3, $board['on_agenda']['total']);   // on_agenda + two in_meeting
        $this->assertSame(1, $board['awaiting_municipal_approval']['total']); // legacy `decided`
        $this->assertSame(1, $board['awaiting_central_approval']['total']);
        $this->assertSame(1, $board['in_execution']['total']); // `executed`, approved but not closed
        $this->assertSame(0, $board['closed']['total']);
        // Bucket 9 cross-cuts the rest: the overdue file is counted here AND
        // in whichever live bucket its status puts it.
        $this->assertSame(1, $board['overdue']['total']);
        $this->assertSame(7, $board['new']['total']);

        // Stage 81 — Appendix 10's warning tally rides the same screen,
        // because the appendix addresses those alerts to مقرر اللجنة.
        $this->assertCount(10, $response->json('data.early_warnings.summary'));

        $this->assertSame($meeting->id, $response->json('data.next_meeting.id'));
        $this->assertSame(2, $response->json('data.next_meeting.agenda_items_count'));
    }

    public function test_dashboard_next_meeting_is_null_when_none_upcoming(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');

        $this->actingAs($head, 'sanctum')
            ->getJson('/api/meetings/dashboard')
            ->assertOk()
            ->assertJsonPath('data.next_meeting', null);
    }

    private function committeeRequest(string $statusCode): Request
    {
        return $this->requestAt('receive_from_committee', $statusCode);
    }

    private function requestAt(string $stageCode, string $statusCode, ?User $employee = null): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'اختبار الطلبات المرشحة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $employee?->id,
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
