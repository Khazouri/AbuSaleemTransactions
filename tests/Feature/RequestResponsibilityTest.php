<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\Lifecycle\RequestResponsibilityService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 83 — [D] Appendix 17 (المسؤول الحالي) and Appendix 18 (الإجراء التالي).
 *
 * The property under test throughout is the one the appendices actually state:
 * both fields answer for **every** request, and neither is ever empty or
 * generic.
 */
class RequestResponsibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_seeded_status_resolves_a_named_party_and_a_named_next_action(): void
    {
        $this->seed(DatabaseSeeder::class);
        $service = app(RequestResponsibilityService::class);

        foreach (RequestStatus::all() as $status) {
            $requestRecord = $this->requestAt('requirements_check', $status->code);
            $answer = $service->for($requestRecord);

            $this->assertArrayHasKey($answer['responsible']['code'], RequestResponsibilityService::PARTIES, $status->code);
            $this->assertArrayHasKey($answer['next_action']['code'], RequestResponsibilityService::NEXT_ACTIONS, $status->code);
            $this->assertNotSame('', trim($answer['responsible']['ar']), $status->code);
            $this->assertNotSame('', trim($answer['next_action']['ar']), $status->code);
        }
    }

    /**
     * Appendix 18's own rule: "يمنع وجود معاملة بحالة عامة مثل (قيد الإجراء)
     * دون معرفة ما المطلوب فعليًا". `in_review` is exactly such a broad status,
     * so the stage has to answer instead — and it does, differently per stage.
     */
    public function test_a_broad_status_falls_through_to_the_stage_rather_than_a_generic_answer(): void
    {
        $this->seed(DatabaseSeeder::class);
        $service = app(RequestResponsibilityService::class);

        $atCheck = $service->for($this->requestAt('requirements_check', 'in_review'));
        $atStudy = $service->for($this->requestAt('observations', 'in_review'));

        $this->assertSame('completeness_check', $atCheck['next_action']['code']);
        $this->assertSame('prepare_presentation_memo', $atStudy['next_action']['code']);
    }

    /** A status that names its own owner beats whatever the stage would say. */
    public function test_a_file_waiting_on_the_employee_says_so_whatever_stage_it_is_parked_at(): void
    {
        $this->seed(DatabaseSeeder::class);
        $answer = app(RequestResponsibilityService::class)
            ->for($this->requestAt('reviewer_review', 'incomplete'));

        $this->assertSame('employee', $answer['responsible']['code']);
        $this->assertSame('complete_documents', $answer['next_action']['code']);
    }

    /**
     * The manager-gated hop carries no `required_role_id` at all — it names a
     * person by relationship — so it has to be read from
     * `requires_submitter_manager` rather than through a role.
     */
    public function test_the_manager_gated_stage_resolves_the_direct_manager(): void
    {
        $this->seed(DatabaseSeeder::class);
        $answer = app(RequestResponsibilityService::class)
            ->for($this->requestAt('direct_manager_review', 'in_review'));

        $this->assertSame('direct_manager', $answer['responsible']['code']);
        $this->assertSame('manager_review', $answer['next_action']['code']);
    }

    public function test_a_concluded_file_reports_the_archive_and_no_action_required(): void
    {
        $this->seed(DatabaseSeeder::class);
        $answer = app(RequestResponsibilityService::class)
            ->for($this->requestAt('final_approval_archiving', 'completed_closed'));

        $this->assertSame('archive', $answer['responsible']['code']);
        $this->assertSame('closed', $answer['next_action']['code']);
    }

    /** Appendix 17's "يجب أن تظهر في كل معاملة" — including on the list. */
    public function test_both_fields_reach_the_list_and_the_detail_payloads(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $requestRecord = $this->requestAt('local_governance_ministry', 'awaiting_central_approval');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/requests')
            ->assertOk()
            ->assertJsonPath('data.0.responsibility.responsible.code', 'ministry')
            ->assertJsonPath('data.0.responsibility.next_action.code', 'await_ministry_reply');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.responsibility.responsible.ar', 'وزارة الحكم المحلي')
            ->assertJsonPath('data.responsibility.next_action.ar', 'انتظار رد الوزارة');
    }

    /**
     * Stage 81's alert list and this derivation are now one predicate, so a
     * warning row and the request screen cannot name different people.
     */
    public function test_the_early_warning_list_names_the_same_party_as_the_request_screen(): void
    {
        $this->seed(DatabaseSeeder::class);
        $requestRecord = $this->requestAt('local_governance_ministry', 'awaiting_central_approval');

        $service = app(RequestResponsibilityService::class);

        $this->assertSame(
            $service->for($requestRecord)['responsible']['ar'],
            $service->responsibleLabel($requestRecord, 'ar'),
        );
        $this->assertSame('Ministry of Local Governance', $service->responsibleLabel($requestRecord, 'en'));
    }

    // --- fixtures ----------------------------------------------------------

    private function requestAt(string $stageCode, string $statusCode): Request
    {
        $employee = User::factory()->create(['is_active' => true]);
        $employee->roles()->attach(Role::where('code', 'R01')->value('id'));

        return Request::create([
            'reference_number' => 'PM-COM/2026/'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب اختبار المسؤولية',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $employee->id,
            'submitted_at' => now(),
        ])->load(['status', 'currentStage']);
    }
}
