<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stage 54 — [D] Art. 45's 6-question jurisdiction test at requirements_check,
 * gating that stage's approve / declare_no_jurisdiction / reject_formally
 * outcomes, plus [A] §4 stage 3's 4-result outcome set.
 */
class RequirementsCheckJurisdictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_the_jurisdiction_test_round_trips_through_the_detail_resource(): void
    {
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review');

        $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.jurisdiction_test', null);

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/jurisdiction-test", $this->answers())
            ->assertOk()
            ->assertJsonPath('data.jurisdiction_test.has_legal_basis', true)
            ->assertJsonPath('data.jurisdiction_test.final_approval_authority', 'عميد البلدية')
            ->assertJsonPath('data.jurisdiction_test.requires_central_approval', false);

        $this->assertNotNull($requestRecord->refresh()->jurisdiction_test);
    }

    public function test_a_partial_answer_set_is_rejected(): void
    {
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review');

        $answers = $this->answers();
        unset($answers['final_approval_authority']);

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/jurisdiction-test", $answers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('final_approval_authority');

        $this->assertNull($requestRecord->refresh()->jurisdiction_test);
    }

    public function test_approve_declare_no_jurisdiction_and_reject_formally_are_refused_until_the_test_is_recorded(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');

        foreach (['approve', 'declare_no_jurisdiction', 'reject_formally'] as $action) {
            $requestRecord = $this->requestAt('requirements_check', 'in_review');

            $payload = ['action' => $action, 'comment' => 'سبب.'];
            if ($action === 'approve') {
                $payload['signature'] = UploadedFile::fake()->image('signature.png', 960, 330);
            }

            $this->actingAs($reviewer, 'sanctum')
                ->post("/api/requests/{$requestRecord->id}/transition", $payload, ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('action');

            $this->assertSame('requirements_check', $requestRecord->refresh()->currentStage->code);
        }
    }

    public function test_return_missing_docs_and_cancel_are_unaffected_by_the_gate(): void
    {
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review');

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", [
                'action' => 'return_missing_docs',
                'comment' => 'المستند غير مرفق.',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'receive_from_municipality')
            ->assertJsonPath('data.status.code', 'incomplete');
    }

    public function test_the_three_gated_actions_succeed_once_the_test_is_recorded(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');

        $approved = $this->requestAt('requirements_check', 'in_review', jurisdictionTest: $this->answers());
        $this->actingAs($reviewer, 'sanctum')
            ->post("/api/requests/{$approved->id}/transition", [
                'action' => 'approve',
                'signature' => UploadedFile::fake()->image('signature.png', 960, 330),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'reviewer_review')
            ->assertJsonPath('data.status.code', 'in_review');

        $referred = $this->requestAt('requirements_check', 'in_review', jurisdictionTest: $this->answers());
        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/requests/{$referred->id}/transition", [
                'action' => 'declare_no_jurisdiction',
                'comment' => 'الموضوع خارج اختصاص اللجنة.',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'requirements_check')
            ->assertJsonPath('data.status.code', 'outside_jurisdiction');

        $rejected = $this->requestAt('requirements_check', 'in_review', jurisdictionTest: $this->answers());
        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/requests/{$rejected->id}/transition", [
                'action' => 'reject_formally',
                'comment' => 'الطلب مكرر ولا يستوفي الشروط.',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'requirements_check')
            ->assertJsonPath('data.status.code', 'rejected');
    }

    public function test_available_transitions_excludes_the_gated_actions_until_recorded(): void
    {
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review');

        $before = $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();
        $this->assertFalse(collect($before->json('data.available_actions'))->contains('approve'));
        $this->assertFalse(collect($before->json('data.available_actions'))->contains('declare_no_jurisdiction'));
        $this->assertFalse(collect($before->json('data.available_actions'))->contains('reject_formally'));
        $this->assertTrue(collect($before->json('data.available_actions'))->contains('return_missing_docs'));

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/jurisdiction-test", $this->answers())
            ->assertOk();

        $after = $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();
        $this->assertTrue(collect($after->json('data.available_actions'))->contains('approve'));
        $this->assertTrue(collect($after->json('data.available_actions'))->contains('declare_no_jurisdiction'));
        $this->assertTrue(collect($after->json('data.available_actions'))->contains('reject_formally'));
    }

    public function test_the_reviewer_approval_queue_is_also_gated(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review');

        $this->actingAs($reviewer, 'sanctum')
            ->post("/api/approvals/reviewer/{$requestRecord->id}", [
                'signature' => UploadedFile::fake()->image('signature.png', 960, 330),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');

        $this->assertSame('requirements_check', $requestRecord->refresh()->currentStage->code);
    }

    private function answers(): array
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

    private function requestAt(
        string $stageCode,
        string $statusCode,
        ?array $jurisdictionTest = null,
    ): Request {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'اختبار الاختصاص',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'submitted_at' => now(),
            'decision_grade' => 10,
            'jurisdiction_test' => $jurisdictionTest,
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
