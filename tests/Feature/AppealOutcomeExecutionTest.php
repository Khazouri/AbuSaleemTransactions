<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AppealStatus;
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
use Tests\TestCase;

/**
 * Stage 64, Track J — each of Art. 75 point 5's five outcomes gets its real
 * effect on the *original* Request, a deliberate second step after Stage
 * 63's own vote/decision recording (AppealController::executeOutcome, via
 * App\Services\AppealOutcomeExecutor). See AGENT_NOTES.md's Stage 64 plan
 * entry for the full per-outcome design and the judgment calls behind it.
 *
 * Recording the committee's vote (R03/R04, `decisions.approve`) and
 * executing its outcome (R02, `appeals.edit`) are two different grants,
 * matching every other Track J action on this screen — so the fixture
 * carries a separate $verifier (R02) alongside the committee's $head/$member.
 */
class AppealOutcomeExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_execution_is_refused_before_the_committee_has_decided(): void
    {
        [, , , , , $appeal, , $verifier] = $this->appealFixture();

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/execute-outcome")
            ->assertStatus(422);
    }

    public function test_execution_is_refused_for_the_appellant_acting_on_their_own_appeal(): void
    {
        // Holds R02 too, so the request clears the appeals.edit permission
        // gate and actually reaches the controller's self-action check —
        // an ordinary R01-only appellant is blocked earlier, by the route
        // middleware itself, same precedent AppealJurisdictionReviewTest
        // already established for verify()/recordJurisdictionTest().
        $dualRoleAppellant = $this->userWithRole('R01');
        $dualRoleAppellant->roles()->attach(Role::where('code', 'R02')->value('id'));

        [, , , , , $appeal] = $this->decidedAppealFixture('appeal_reject', $dualRoleAppellant);

        $this->actingAs($dualRoleAppellant, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/execute-outcome")
            ->assertStatus(422);
    }

    public function test_execution_is_a_one_shot_action(): void
    {
        [, , , , , $appeal, , $verifier] = $this->decidedAppealFixture('appeal_reject');

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/execute-outcome")
            ->assertOk();

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/execute-outcome")
            ->assertStatus(422);
    }

    public function test_a_role_without_appeals_edit_is_refused(): void
    {
        [, , , , , $appeal] = $this->decidedAppealFixture('appeal_reject');
        $stranger = $this->userWithRole('R01');

        $this->actingAs($stranger, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/execute-outcome")
            ->assertStatus(403);
    }

    public function test_appeal_accept_withdraws_the_original_decision_and_becomes_terminal(): void
    {
        [, , , , , $appeal, , $verifier] = $this->decidedAppealFixture('appeal_accept');
        $originalRequest = $appeal->originalRequest;

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/execute-outcome")
            ->assertOk()
            ->assertJsonPath('data.outcome_execution.executed_by.id', $verifier->id)
            ->assertJsonPath('data.committee_decision.outcome', 'appeal_accept');

        $this->assertSame('decision_withdrawn', $originalRequest->refresh()->status->code);
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $originalRequest->id,
            'to_status_id' => RequestStatus::where('code', 'decision_withdrawn')->value('id'),
        ]);

        $this->assertRequestIsTerminal($originalRequest);
    }

    public function test_appeal_partial_accept_amends_the_original_decision(): void
    {
        [, , , , , $appeal, , $verifier] = $this->decidedAppealFixture('appeal_partial_accept');
        $originalRequest = $appeal->originalRequest;

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/execute-outcome")
            ->assertOk();

        $this->assertSame('decision_amended', $originalRequest->refresh()->status->code);
        $this->assertRequestIsTerminal($originalRequest);
    }

    public function test_appeal_reject_leaves_the_original_request_completely_untouched(): void
    {
        [, , , , , $appeal, , $verifier] = $this->decidedAppealFixture('appeal_reject');
        $originalRequest = $appeal->originalRequest->fresh();
        $statusId = $originalRequest->status_id;
        $stageId = $originalRequest->current_stage_id;

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/execute-outcome")
            ->assertOk();

        $originalRequest->refresh();
        $this->assertSame($statusId, $originalRequest->status_id);
        $this->assertSame($stageId, $originalRequest->current_stage_id);
    }

    public function test_appeal_refer_sets_referred_to_other_body_without_moving_the_stage_and_stays_non_terminal(): void
    {
        [, , , , , $appeal, , $verifier] = $this->decidedAppealFixture('appeal_refer');
        $originalRequest = $appeal->originalRequest;
        $stageId = $originalRequest->current_stage_id;

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/execute-outcome")
            ->assertOk();

        $originalRequest->refresh();
        $this->assertSame('referred_to_other_body', $originalRequest->status->code);
        $this->assertSame($stageId, $originalRequest->current_stage_id);
        $this->assertRequestIsNotTerminal($originalRequest);
    }

    public function test_appeal_redo_requires_a_stage_and_is_refused_for_an_excluded_stage(): void
    {
        [, , , , , $appeal, , $verifier] = $this->decidedAppealFixture('appeal_redo');

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/execute-outcome")
            ->assertStatus(422);

        $excludedStageId = WorkflowStage::where('code', 'receive_and_register')->value('id');
        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/execute-outcome", ['redo_stage_id' => $excludedStageId])
            ->assertStatus(422);

        // A target stage ordered after the request's current one is covered
        // directly against WorkflowService::reopenAtStage() in
        // tests/Unit/WorkflowServiceTest.php — the fixture's original
        // request already sits at the last stage (order 12), so there is no
        // literal "forward" stage to probe through the HTTP layer here.
        $this->assertNull($appeal->fresh()->outcome_executed_at);
    }

    public function test_appeal_redo_reopens_the_original_request_at_the_named_stage_with_a_full_audit_trail(): void
    {
        [, , , , , $appeal, , $verifier] = $this->decidedAppealFixture('appeal_redo');
        $originalRequest = $appeal->originalRequest;
        $targetStage = WorkflowStage::where('code', 'reviewer_review')->firstOrFail();

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/execute-outcome", ['redo_stage_id' => $targetStage->id])
            ->assertOk()
            ->assertJsonPath('data.outcome_execution.redo_stage.code', 'reviewer_review');

        $originalRequest->refresh();
        $this->assertSame($targetStage->id, $originalRequest->current_stage_id);
        $this->assertSame('reopened_by_appeal', $originalRequest->status->code);

        $this->assertDatabaseHas('request_stage_logs', [
            'request_id' => $originalRequest->id,
            'to_stage_id' => $targetStage->id,
            'action' => 'appeal_redo',
        ]);
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $originalRequest->id,
            'to_status_id' => RequestStatus::where('code', 'reopened_by_appeal')->value('id'),
        ]);
        $this->assertSame($targetStage->id, $appeal->fresh()->outcome_redo_stage_id);

        // Non-terminal, and available_actions now matches the target
        // stage's normal actions rather than staying empty (proving this
        // directly via the reviewer_review-appropriate role, since R07 —
        // used by the other outcome tests' generic non-terminal check —
        // holds no rule at this earlier stage and would otherwise 404).
        $reviewer = $this->userWithRole('R02');
        $response = $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/requests/{$originalRequest->id}")
            ->assertOk();
        $this->assertContains('forward', $response->json('data.available_actions'));
    }

    /** @return array{0: User, 1: User, 2: Committee, 3: Meeting, 4: MeetingRequest, 5: Appeal, 6: User, 7: User} */
    private function appealFixture(?User $appellant = null): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');
        $verifier = $this->userWithRole('R02');

        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع عرض التظلمات',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);
        $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);

        $appellant ??= $this->userWithRole('R01');
        $appeal = $this->appealAt($appellant, 'legal_review');

        $agendaItem = $meeting->agendaItems()->create([
            'appeal_id' => $appeal->id,
            'item_type' => 'appeal',
            'agenda_order' => 1,
        ]);

        return [$head, $member, $committee, $meeting, $agendaItem, $appeal, $appellant, $verifier];
    }

    /** @return array{0: User, 1: User, 2: Committee, 3: Meeting, 4: MeetingRequest, 5: Appeal, 6: User, 7: User} */
    private function decidedAppealFixture(string $outcome, ?User $appellant = null): array
    {
        [$head, $member, $committee, $meeting, $agendaItem, $appeal, $appellant, $verifier] = $this->appealFixture($appellant);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => $outcome])
            ->assertCreated();
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => $outcome])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", ['comment' => 'سبب القرار'])
            ->assertCreated()
            ->assertJsonPath('data.outcome', $outcome);

        $this->assertSame('committee_presentation', $appeal->fresh()->status->code);

        return [$head, $member, $committee, $meeting, $agendaItem, $appeal->fresh(), $appellant, $verifier];
    }

    private function requestFixture(User $creator): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب صدر بشأنه قرار',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'final_approved')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'final_approval_archiving')->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now(),
        ]);
    }

    private function appealAt(User $appellant, string $statusCode): Appeal
    {
        $target = $this->requestFixture($appellant);

        return Appeal::create([
            'appellant_user_id' => $appellant->id,
            'original_request_id' => $target->id,
            'original_decision_reference' => 'قرار اعتماد نهائي',
            'known_at' => now()->subDay(),
            'appeal_reasons' => 'القرار خالف الإجراءات المتبعة.',
            'final_request' => 'إعادة النظر في القرار.',
            'appeal_status_id' => AppealStatus::where('code', $statusCode)->value('id'),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }

    /**
     * A terminal request refuses every ordinary workflow action, so its
     * available_actions list is empty. Viewed as the request's own creator
     * — RequestVisibility's creator branch is never terminal-status-gated,
     * unlike the assignment (role-match) branch a non-creator like R07 would
     * otherwise need, which WOULD 404 once the status is terminal.
     */
    private function assertRequestIsTerminal(Request $requestRecord): void
    {
        $response = $this->actingAs($requestRecord->createdBy, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();

        $this->assertSame([], $response->json('data.available_actions'));
    }

    /** A non-terminal request still exposes its stage's normal actions. */
    private function assertRequestIsNotTerminal(Request $requestRecord): void
    {
        $r07 = $this->userWithRole('R07');
        $response = $this->actingAs($r07, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();

        $this->assertNotSame([], $response->json('data.available_actions'));
    }
}
