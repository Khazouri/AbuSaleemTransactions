<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\Department;
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
 * Stage 62, Track J — Art. 77's jurisdiction test (AppealController::
 * recordJurisdictionTest()) and Art. 75 point 4's legal-review checklist
 * (AppealController::recordLegalReview()), both gating whether an appeal can
 * reach Stage 63's committee presentation.
 */
class AppealJurisdictionReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_committee_competent_answer_advances_to_file_assembly_and_records_who_when(): void
    {
        $employee = $this->userWithRole('R01');
        $reviewer = $this->userWithRole('R02');
        $appeal = $this->appealAt($employee, 'formal_verification');

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/jurisdiction-test", [
                'competent_body' => 'committee',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'file_assembly')
            ->assertJsonPath('data.jurisdiction_test.competent_body', 'committee')
            ->assertJsonPath('data.jurisdiction_test.tested_by.id', $reviewer->id);

        $this->assertDatabaseHas('appeals', [
            'id' => $appeal->id,
            'jurisdiction_tested_by_user_id' => $reviewer->id,
        ]);
        $this->assertNotNull($appeal->fresh()->jurisdiction_tested_at);
    }

    public function test_the_disciplinary_board_answer_terminates_the_appeal_per_article_77(): void
    {
        $employee = $this->userWithRole('R01');
        $reviewer = $this->userWithRole('R02');
        $appeal = $this->appealAt($employee, 'formal_verification');

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/jurisdiction-test", [
                'competent_body' => 'disciplinary_or_court',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'outside_jurisdiction')
            ->assertJsonPath('data.jurisdiction_test.competent_body', 'disciplinary_or_court');
    }

    /**
     * Art. 77 only names the disciplinary/court case explicitly, but the
     * same "this is not the committee's matter" logic applies to every
     * non-committee answer — none of the four alternatives are the
     * committee either.
     */
    public function test_every_non_committee_answer_terminates_the_appeal(): void
    {
        foreach (['mayor', 'ministry', 'other_body'] as $competentBody) {
            $employee = $this->userWithRole('R01');
            $reviewer = $this->userWithRole('R02');
            $appeal = $this->appealAt($employee, 'formal_verification');

            $this->actingAs($reviewer, 'sanctum')
                ->patchJson("/api/appeals/{$appeal->id}/jurisdiction-test", [
                    'competent_body' => $competentBody,
                ])
                ->assertOk()
                ->assertJsonPath('data.status.code', 'outside_jurisdiction');
        }
    }

    public function test_jurisdiction_test_is_refused_before_formal_verification(): void
    {
        $employee = $this->userWithRole('R01');
        $reviewer = $this->userWithRole('R02');
        $appeal = $this->appealAt($employee, 'submitted');

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/jurisdiction-test", [
                'competent_body' => 'committee',
            ])
            ->assertUnprocessable();
    }

    public function test_jurisdiction_test_is_one_shot(): void
    {
        $employee = $this->userWithRole('R01');
        $reviewer = $this->userWithRole('R02');
        $appeal = $this->appealAt($employee, 'file_assembly');

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/jurisdiction-test", [
                'competent_body' => 'committee',
            ])
            ->assertUnprocessable();
    }

    public function test_the_appellant_cannot_test_jurisdiction_on_their_own_appeal(): void
    {
        $employee = $this->userWithRole('R01');
        $employee->roles()->attach(Role::where('code', 'R02')->value('id'));
        $appeal = $this->appealAt($employee, 'formal_verification');

        $this->actingAs($employee, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/jurisdiction-test", [
                'competent_body' => 'committee',
            ])
            ->assertUnprocessable();
    }

    public function test_a_role_without_appeals_edit_permission_is_refused_the_jurisdiction_test(): void
    {
        $employee = $this->userWithRole('R01');
        $anotherEmployee = $this->userWithRole('R01');
        $appeal = $this->appealAt($employee, 'formal_verification');

        $this->actingAs($anotherEmployee, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/jurisdiction-test", [
                'competent_body' => 'committee',
            ])
            ->assertForbidden();
    }

    public function test_legal_review_advances_to_legal_review_status_and_records_who_when(): void
    {
        $employee = $this->userWithRole('R01');
        $reviewer = $this->userWithRole('R02');
        $appeal = $this->appealAt($employee, 'file_assembly');

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/legal-review", $this->legalReviewPayload())
            ->assertOk()
            ->assertJsonPath('data.status.code', 'legal_review')
            ->assertJsonPath('data.legal_review.checks.factual_error', false)
            ->assertJsonPath('data.legal_review.checks.formation_or_reasoning_defect', true)
            ->assertJsonPath('data.legal_review.reviewed_by.id', $reviewer->id);

        $this->assertDatabaseHas('appeals', [
            'id' => $appeal->id,
            'legal_reviewed_by_user_id' => $reviewer->id,
        ]);
        $this->assertNotNull($appeal->fresh()->legal_reviewed_at);
    }

    public function test_legal_review_is_refused_before_jurisdiction_is_confirmed_with_the_committee(): void
    {
        $employee = $this->userWithRole('R01');
        $reviewer = $this->userWithRole('R02');
        $appeal = $this->appealAt($employee, 'formal_verification');

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/legal-review", $this->legalReviewPayload())
            ->assertUnprocessable();
    }

    public function test_legal_review_requires_all_five_questions_answered_together(): void
    {
        $employee = $this->userWithRole('R01');
        $reviewer = $this->userWithRole('R02');
        $appeal = $this->appealAt($employee, 'file_assembly');

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/legal-review", [
                'factual_error' => false,
                'legal_text_violation' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_documents', 'formation_or_reasoning_defect', 'issued_by_competent_body']);
    }

    public function test_the_appellant_cannot_review_their_own_appeal(): void
    {
        $employee = $this->userWithRole('R01');
        $employee->roles()->attach(Role::where('code', 'R02')->value('id'));
        $appeal = $this->appealAt($employee, 'file_assembly');

        $this->actingAs($employee, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/legal-review", $this->legalReviewPayload())
            ->assertUnprocessable();
    }

    public function test_a_role_without_appeals_edit_permission_is_refused_the_legal_review(): void
    {
        $employee = $this->userWithRole('R01');
        $anotherEmployee = $this->userWithRole('R01');
        $appeal = $this->appealAt($employee, 'file_assembly');

        $this->actingAs($anotherEmployee, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/legal-review", $this->legalReviewPayload())
            ->assertForbidden();
    }

    // --- helpers ------------------------------------------------------------

    private function legalReviewPayload(): array
    {
        return [
            'factual_error' => false,
            'legal_text_violation' => false,
            'new_documents' => true,
            'formation_or_reasoning_defect' => true,
            'issued_by_competent_body' => true,
        ];
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
}
