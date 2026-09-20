<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\IntakeGateService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 84 — nobody certifies their own file.
 *
 * [D] Appendix 19's مصفوفة الفصل بين الصلاحيات opens with "لا يكون مقدم الطلب
 * هو معتمد الطلب"; Appendix 6's RACI leaves the الموظف column empty for both
 * فحص اكتمال ملف اللجنة and القيد, giving each to مقرر اللجنة; and Appendix
 * 45's صلاحية الموظف is "إنشاء طلب · رفع مستند · استكمال نقص · متابعة الحالة
 * المسموحة" — no فحص and no قيد anywhere in it.
 *
 * Two layers, because they catch different people. The grant (R01 dropped from
 * `notes_attachments,edit`) stops the employee; the creator block additionally
 * stops an officer who filed on someone's behalf and would otherwise be
 * checking their own work. The appeals side has carried the same block since
 * Stage 62 — see AppealController::recordJurisdictionTest().
 */
class GateAuthorshipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_an_employee_can_no_longer_record_either_gate_on_their_own_file(): void
    {
        $employee = $this->userWithRoles(['R01']);
        $requestRecord = $this->requestAtRequirementsCheck($employee);

        $this->actingAs($employee, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/jurisdiction-test", $this->jurisdictionAnswers())
            ->assertForbidden();

        $this->actingAs($employee, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/intake-gate", $this->intakeGatePayload($requestRecord))
            ->assertForbidden();

        $requestRecord->refresh();
        $this->assertNull($requestRecord->jurisdiction_test);
        $this->assertNull($requestRecord->intake_gate);
    }

    /**
     * The grant alone is not the whole rule — Appendix 19 is about the person,
     * not the role. An officer who filed the request is still its مقدم الطلب.
     *
     * Stage 95 widened the refusal to صاحب العلاقة as well, so these two
     * messages now name both parties; the rule this test pins is unchanged.
     */
    public function test_the_creator_is_refused_even_when_they_hold_the_grant(): void
    {
        $officer = $this->userWithRoles(['R01', 'R02']);
        $requestRecord = $this->requestAtRequirementsCheck($officer);

        $this->actingAs($officer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/jurisdiction-test", $this->jurisdictionAnswers())
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يجوز لمقدّم الطلب أو صاحب العلاقة إجراء اختبار الاختصاص على الطلب بنفسه.');

        $this->actingAs($officer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/intake-gate", $this->intakeGatePayload($requestRecord))
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يجوز لمقدّم الطلب أو صاحب العلاقة إثبات اكتمال الملف بنفسه.');

        $requestRecord->refresh();
        $this->assertNull($requestRecord->jurisdiction_test);
        $this->assertNull($requestRecord->intake_gate);
    }

    /**
     * Both halves of gate 1 now name their author. Before this stage the
     * jurisdiction test was the only per-request control record in the table
     * with no recorder and no timestamp at all.
     */
    public function test_a_non_creator_records_both_halves_of_gate_one_and_the_payload_names_the_recorder(): void
    {
        $employee = $this->userWithRoles(['R01']);
        $reviewer = $this->userWithRoles(['R02']);
        $requestRecord = $this->requestAtRequirementsCheck($employee);

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/jurisdiction-test", $this->jurisdictionAnswers())
            ->assertOk();

        $response = $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/intake-gate", $this->intakeGatePayload($requestRecord))
            ->assertOk();

        $response->assertJsonPath('data.control_gates.intake.jurisdiction_test.recorded', true)
            ->assertJsonPath('data.control_gates.intake.jurisdiction_test.recorded_by.id', $reviewer->id)
            ->assertJsonPath('data.control_gates.intake.recorded_by.id', $reviewer->id);

        $this->assertNotNull($response->json('data.control_gates.intake.jurisdiction_test.recorded_at'));
        // Both halves answered, so the قيد hop is no longer refused.
        $this->assertNull($response->json('data.control_gates.intake.refusal'));

        $requestRecord->refresh();
        $this->assertSame($reviewer->id, $requestRecord->jurisdiction_tested_by_user_id);
        $this->assertNotNull($requestRecord->jurisdiction_tested_at);
    }

    /**
     * Recorded rather than incidental: dropping R01 from `notes_attachments,
     * edit` also takes Stage 47's financial-impact correction away from the
     * employee. Appendix 45's صلاحية الموظف carries no editing capability at
     * all, and R02 keeps it.
     */
    public function test_an_employee_can_no_longer_correct_the_financial_impact_flag(): void
    {
        $employee = $this->userWithRoles(['R01']);
        $reviewer = $this->userWithRoles(['R02']);
        $requestRecord = $this->requestAtRequirementsCheck($employee);

        $this->actingAs($employee, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/financial-impact", ['has_financial_impact' => true])
            ->assertForbidden();

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/financial-impact", ['has_financial_impact' => true])
            ->assertOk();
    }

    /**
     * The bug this stage also closes. reopen() cleared `intake_gate` and
     * `execution_soundness` with their who/when pairs but never
     * `jurisdiction_test`, so a reopened file arrived already satisfying the
     * `!== null` gate on `requirements_check → approve` using the answers from
     * the lap before — exactly the staleness clearing the other two prevents.
     */
    public function test_reopening_clears_the_jurisdiction_test_so_the_next_lap_cannot_ride_the_previous_answers(): void
    {
        $employee = $this->userWithRoles(['R01']);
        $reviewer = $this->userWithRoles(['R02']);

        $requestRecord = $this->requestAtRequirementsCheck($employee);
        $requestRecord->forceFill([
            'current_stage_id' => WorkflowStage::where('code', 'final_approval_archiving')->value('id'),
            'status_id' => RequestStatus::where('code', 'completed_closed')->value('id'),
            'jurisdiction_test' => $this->jurisdictionAnswers(),
            'jurisdiction_tested_by_user_id' => $reviewer->id,
            'jurisdiction_tested_at' => now(),
        ])->save();

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/reopen", [
                'reason_code' => 'new_document',
                'target_stage_id' => WorkflowStage::where('code', 'requirements_check')->value('id'),
                'note' => 'ورد مستند جديد يغير الوقائع.',
            ])
            ->assertOk()
            ->assertJsonPath('data.control_gates.intake.jurisdiction_test.recorded', false);

        $requestRecord->refresh();
        $this->assertNull($requestRecord->jurisdiction_test);
        $this->assertNull($requestRecord->jurisdiction_tested_by_user_id);
        $this->assertNull($requestRecord->jurisdiction_tested_at);
    }

    /** @return array<string, mixed> */
    private function jurisdictionAnswers(): array
    {
        return [
            'has_legal_basis' => true,
            'employee_covered' => true,
            'within_municipal_jurisdiction' => true,
            'committee_decides' => true,
            'final_approval_authority' => 'عميد البلدية',
            'requires_central_approval' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function intakeGatePayload(Request $requestRecord): array
    {
        $gate = app(IntakeGateService::class);

        return [
            'documents' => array_fill_keys(array_keys($gate->requiredDocuments($requestRecord)), 'present'),
            'facts_verified' => true,
        ];
    }

    private function requestAtRequirementsCheck(User $creator): Request
    {
        return Request::create([
            'reference_number' => null,
            'intake_receipt_number' => 'PM-RCV/2026/'.str_pad((string) (Request::count() + 1), 6, '0', STR_PAD_LEFT),
            'title' => 'طلب عند فحص الاكتمال',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_review')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'requirements_check')->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now(),
            'decision_grade' => 10,
        ]);
    }

    /** @param  array<int, string>  $roleCodes */
    private function userWithRoles(array $roleCodes): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::whereIn('code', $roleCodes)->pluck('id'));

        return $user;
    }
}
