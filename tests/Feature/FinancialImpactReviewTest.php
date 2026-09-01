<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\FinancialImpactReviewNotification;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Stage 47 — قسم المرتبات والمزايا has no seat in workflow_transitions ([D]
 * doesn't name one; see AGENT_NOTES.md), so this is an advisory notification
 * plus a bounded RequestVisibility grant, not a blocking parallel-review gate.
 */
class FinancialImpactReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_reaching_the_study_stage_notifies_only_active_salaries_and_benefits_users_when_flagged(): void
    {
        Notification::fake();

        $salUser = $this->userInSalariesDepartment(active: true);
        $inactiveSalUser = $this->userInSalariesDepartment(active: false);
        $unrelatedUser = $this->userWithRole('R04');
        $creator = $this->userWithRole('R01');
        $actor = $this->userWithRole('R02');

        $requestRecord = $this->requestAtStage('reviewer_review', $creator, hasFinancialImpact: true);

        app(WorkflowService::class)->transition($requestRecord, 'forward', $actor);

        Notification::assertSentTo($salUser, FinancialImpactReviewNotification::class);
        Notification::assertNotSentTo($inactiveSalUser, FinancialImpactReviewNotification::class);
        Notification::assertNotSentTo($unrelatedUser, FinancialImpactReviewNotification::class);
    }

    public function test_reaching_the_study_stage_notifies_nobody_when_the_request_has_no_financial_impact(): void
    {
        Notification::fake();

        $salUser = $this->userInSalariesDepartment(active: true);
        $creator = $this->userWithRole('R01');
        $actor = $this->userWithRole('R02');

        $requestRecord = $this->requestAtStage('reviewer_review', $creator, hasFinancialImpact: false);

        app(WorkflowService::class)->transition($requestRecord, 'forward', $actor);

        Notification::assertNotSentTo($salUser, FinancialImpactReviewNotification::class);
    }

    /**
     * The committee can bounce a request back to observations for re-study
     * (Stage 32's `return_to_study`) — this should re-notify too, since
     * stageChanged() fires on every landing at `observations`, not only the
     * first.
     */
    public function test_returning_to_study_from_committee_notifies_salaries_and_benefits_again(): void
    {
        Notification::fake();

        $salUser = $this->userInSalariesDepartment(active: true);
        $creator = $this->userWithRole('R01');
        $chair = $this->userWithRole('R03');

        $requestRecord = $this->requestAtStage(
            'receive_from_committee',
            $creator,
            hasFinancialImpact: true,
            status: 'in_meeting',
        );

        app(WorkflowService::class)->transition($requestRecord, 'return_to_study', $chair, 'يحتاج مزيداً من الدراسة');

        Notification::assertSentTo($salUser, FinancialImpactReviewNotification::class);
    }

    public function test_a_salaries_and_benefits_user_can_view_a_flagged_request_but_not_an_unflagged_one(): void
    {
        $salUser = $this->userInSalariesDepartment(active: true);
        $creator = $this->userWithRole('R01');

        $flagged = $this->requestAtStage('observations', $creator, hasFinancialImpact: true);
        $unflagged = $this->requestAtStage('observations', $creator, hasFinancialImpact: false);

        $this->actingAs($salUser)
            ->getJson("/api/requests/{$flagged->id}")
            ->assertOk();

        $this->actingAs($salUser)
            ->getJson("/api/requests/{$unflagged->id}")
            ->assertNotFound();
    }

    public function test_the_flag_can_be_corrected_manually_and_the_correction_changes_the_next_notification(): void
    {
        Notification::fake();

        $salUser = $this->userInSalariesDepartment(active: true);
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAtStage('reviewer_review', $reviewer, hasFinancialImpact: false);

        $this->actingAs($reviewer)
            ->patchJson("/api/requests/{$requestRecord->id}/financial-impact", ['has_financial_impact' => true])
            ->assertOk()
            ->assertJsonPath('data.has_financial_impact', true);

        $this->assertTrue($requestRecord->refresh()->has_financial_impact);

        app(WorkflowService::class)->transition($requestRecord, 'forward', $reviewer);

        Notification::assertSentTo($salUser, FinancialImpactReviewNotification::class);
    }

    public function test_updating_the_flag_is_refused_for_a_role_without_notes_attachments_edit(): void
    {
        $bystander = $this->userWithRole('R04');
        $creator = $this->userWithRole('R01');
        $requestRecord = $this->requestAtStage('observations', $creator, hasFinancialImpact: false);

        $this->actingAs($bystander)
            ->patchJson("/api/requests/{$requestRecord->id}/financial-impact", ['has_financial_impact' => true])
            ->assertStatus(403);
    }

    private function requestAtStage(
        string $stageCode,
        User $creator,
        bool $hasFinancialImpact,
        string $status = 'in_review',
    ): Request {
        return Request::create([
            'reference_number' => '2026-ADM-'.fake()->unique()->numerify('######'),
            'title' => 'اختبار الأثر المالي',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $status)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now(),
            'decision_grade' => 10,
            'has_financial_impact' => $hasFinancialImpact,
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }

    private function userInSalariesDepartment(bool $active): User
    {
        $user = User::factory()->create([
            'is_active' => $active,
            'department_id' => Department::where('code', 'SAL')->value('id'),
        ]);
        // A department alone grants nothing on request_details — every real
        // account also holds a base role, same as every other test fixture.
        $user->roles()->attach(Role::where('code', 'R01')->value('id'));

        return $user;
    }
}
