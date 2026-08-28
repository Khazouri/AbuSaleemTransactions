<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Decision;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingTransaction;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionStatus;
use App\Models\TransactionType;
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
        $candidate = $this->committeeTransaction('in_meeting');
        $earlierStage = $this->transactionAt('requirements_check', 'in_review');
        $alreadyApproved = $this->transactionAt('approval_by_authority', 'approved');

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
        $transaction = $this->committeeTransaction('in_meeting');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/committee-candidates/{$transaction->id}/nominate")
            ->assertOk()
            ->assertJsonPath('data.status.code', 'nominated_for_committee');
    }

    public function test_a_member_can_nominate_but_not_defer(): void
    {
        $this->seed(DatabaseSeeder::class);

        $member = $this->userWithRole('R04');
        $transaction = $this->committeeTransaction('in_meeting');

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/committee-candidates/{$transaction->id}/nominate")
            ->assertOk();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/committee-candidates/{$transaction->id}/defer", ['comment' => 'سبب'])
            ->assertStatus(403);
    }

    public function test_defer_requires_a_comment_and_keeps_the_transaction_at_the_committee_stage(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $transaction = $this->committeeTransaction('in_meeting');
        $committeeStageId = $transaction->current_stage_id;

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/committee-candidates/{$transaction->id}/defer")
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/committee-candidates/{$transaction->id}/defer", ['comment' => 'تعارض في المواعيد'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'deferred');

        $this->assertSame($committeeStageId, $transaction->refresh()->current_stage_id);
    }

    public function test_return_to_study_sends_the_transaction_back_to_the_observations_stage(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $transaction = $this->committeeTransaction('in_meeting');
        $observationsStageId = WorkflowStage::where('code', 'observations')->value('id');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/committee-candidates/{$transaction->id}/return-to-study")
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/committee-candidates/{$transaction->id}/return-to-study", ['comment' => 'ينقص مرفق مالي'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'returned')
            ->assertJsonPath('data.current_stage.code', 'observations');

        $this->assertSame($observationsStageId, $transaction->refresh()->current_stage_id);
    }

    public function test_request_completion_requires_a_comment_and_works_before_nomination(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $transaction = $this->committeeTransaction('ready');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/committee-candidates/{$transaction->id}/request-completion")
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/committee-candidates/{$transaction->id}/request-completion", ['comment' => 'ينقص محضر لجنة فرعية'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'completion_required');
    }

    public function test_dashboard_kpis_and_funnel_count_a_hand_built_fixture(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');

        $this->committeeTransaction('in_meeting');
        $this->committeeTransaction('nominated_for_committee');
        $onAgenda = $this->transactionAt('receive_from_committee', 'on_agenda');
        $decided = $this->transactionAt('receive_from_committee', 'decided');
        $this->transactionAt('local_governance_ministry', 'approved');

        // overdue_at isn't mass-assignable (only the SLA sweep sets it in
        // production), so it's set directly here rather than via update().
        $overdue = $this->committeeTransaction('in_meeting');
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
        MeetingTransaction::create([
            'meeting_id' => $meeting->id,
            'transaction_id' => $onAgenda->id,
            'agenda_order' => 1,
            'item_type' => 'employee_request',
        ]);

        $decidedItem = MeetingTransaction::create([
            'meeting_id' => $meeting->id,
            'transaction_id' => $decided->id,
            'agenda_order' => 2,
            'item_type' => 'employee_request',
        ]);
        Decision::create([
            'meeting_transaction_id' => $decidedItem->id,
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

        $this->assertSame(3, $response->json('data.funnel.candidates'));
        $this->assertSame(1, $response->json('data.funnel.on_agenda'));
        $this->assertSame(1, $response->json('data.funnel.decided'));
        $this->assertSame(1, $response->json('data.funnel.closed'));

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

    private function committeeTransaction(string $statusCode): Transaction
    {
        return $this->transactionAt('receive_from_committee', $statusCode);
    }

    private function transactionAt(string $stageCode, string $statusCode): Transaction
    {
        return Transaction::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'اختبار الطلبات المرشحة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'transaction_type_id' => TransactionType::where('code', 'PROM')->value('id'),
            'status_id' => TransactionStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
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
