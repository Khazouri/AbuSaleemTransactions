<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\ScreenRolePermission;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\PassesControlGates;
use Tests\TestCase;

class ApprovalChainTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;

    public function test_reviewer_queue_only_lists_its_checkpoint_and_approval_is_recorded(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        $reviewer = $this->userWithRole('R02');
        $pending = $this->requestAt('requirements_check', 'in_review');
        $this->requestAt('approval_by_authority', 'decided');

        $this->actingAs($reviewer, 'sanctum')
            ->getJson('/api/approvals/reviewer')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.decision_grade', 10)
            ->assertJsonPath('data.0.requires_ministry_approval', true);

        $this->actingAs($reviewer, 'sanctum')
            ->withHeader('Accept', 'application/json')
            ->post("/api/approvals/reviewer/{$pending->id}", [
                'comment' => 'تمت مراجعة المستندات واعتمادها.',
                'signature' => $this->signature(),
            ])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'reviewer_review');

        $this->assertDatabaseHas('approvals', [
            'request_id' => $pending->id,
            'level' => 1,
            'role_id' => Role::where('code', 'R02')->value('id'),
            'approved_by_user_id' => $reviewer->id,
            'action' => 'approve',
            'comment' => 'تمت مراجعة المستندات واعتمادها.',
        ]);
        $approval = $pending->approvals()->firstOrFail();
        $this->assertNotNull($approval->signature_path);
        Storage::disk('local')->assertExists($approval->signature_path);

        $detail = $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/requests/{$pending->id}")
            ->assertOk()
            ->assertJsonPath('data.approvals.0.level', 1)
            ->assertJsonPath('data.approvals.0.role.code', 'R02')
            ->assertJsonPath('data.approvals.0.approved_by.id', $reviewer->id)
            ->assertJsonPath('data.approvals.0.signature_url', route(
                'requests.approvals.signature',
                ['requestRecord' => $pending, 'approval' => $approval],
            ));

        $this->actingAs($reviewer, 'sanctum')
            ->get(parse_url($detail->json('data.approvals.0.signature_url'), PHP_URL_PATH))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_approval_requires_a_png_signature_without_mutating_the_request(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        $reviewer = $this->userWithRole('R02');
        $pending = $this->requestAt('requirements_check', 'in_review');

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/approvals/reviewer/{$pending->id}", [
                'comment' => 'محاولة بلا توقيع.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('signature');

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame('requirements_check', $pending->refresh()->currentStage->code);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_queue_permission_and_checkpoint_both_prevent_cross_level_approval(): void
    {
        $this->seed(DatabaseSeeder::class);

        $reviewer = $this->userWithRole('R02');
        $adminPending = $this->requestAt('approval_by_authority', 'decided');

        $this->actingAs($reviewer, 'sanctum')
            ->getJson('/api/approvals/admin-manager')
            ->assertForbidden();

        $this->actingAs($reviewer, 'sanctum')
            ->withHeader('Accept', 'application/json')
            ->post("/api/approvals/reviewer/{$adminPending->id}", [
                'signature' => $this->signature(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame('approval_by_authority', $adminPending->refresh()->currentStage->code);
    }

    public function test_detail_transition_cannot_bypass_a_revoked_approval_screen_permission(): void
    {
        $this->seed(DatabaseSeeder::class);

        $reviewer = $this->userWithRole('R02');
        $pending = $this->requestAt('requirements_check', 'in_review');
        ScreenRolePermission::query()
            ->where('role_id', Role::where('code', 'R02')->value('id'))
            ->whereHas('screen', fn ($query) => $query->where('code', 'reviewer_approval'))
            ->update(['can_approve' => false]);

        $this->actingAs($reviewer, 'sanctum')
            ->withHeader('Accept', 'application/json')
            ->post("/api/requests/{$pending->id}/transition", [
                'action' => 'approve',
                'signature' => $this->signature(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame('requirements_check', $pending->refresh()->currentStage->code);
    }

    public function test_detail_transition_stores_the_same_signature_evidence_as_the_queue(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        $reviewer = $this->userWithRole('R02');
        $pending = $this->requestAt('requirements_check', 'in_review');

        $this->actingAs($reviewer, 'sanctum')
            ->withHeader('Accept', 'application/json')
            ->post("/api/requests/{$pending->id}/transition", [
                'action' => 'approve',
                'signature' => $this->signature(),
            ])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'reviewer_review')
            ->assertJsonPath('data.approvals.0.level', 1);

        $approval = $pending->approvals()->firstOrFail();
        $this->assertNotNull($approval->signature_path);
        Storage::disk('local')->assertExists($approval->signature_path);
    }

    public function test_a_user_cannot_approve_a_request_they_created(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        $reviewer = $this->userWithRole('R02');
        $pending = $this->requestAt('requirements_check', 'in_review', $reviewer);

        $this->actingAs($reviewer, 'sanctum')
            ->getJson('/api/approvals/reviewer')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/requests/{$pending->id}")
            ->assertOk()
            ->assertJsonMissing(['action' => 'approve']);

        $this->actingAs($reviewer, 'sanctum')
            ->withHeader('Accept', 'application/json')
            ->post("/api/approvals/reviewer/{$pending->id}", [
                'signature' => $this->signature(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');

        $this->actingAs($reviewer, 'sanctum')
            ->withHeader('Accept', 'application/json')
            ->post("/api/requests/{$pending->id}/transition", [
                'action' => 'approve',
                'signature' => $this->signature(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame('requirements_check', $pending->refresh()->currentStage->code);
    }

    private function requestAt(string $stageCode, string $statusCode, ?User $creator = null): Request
    {
        $requestRecord = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب في سلسلة الاعتماد',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $creator?->id,
            'submitted_at' => now(),
            'decision_grade' => 10,
            // Stage 54 gates requirements_check's approve action on this
            // being recorded; set it here so every scenario in this file
            // stays about role/signature/self-approval mechanics, not the
            // new gate (which has its own dedicated test coverage).
            'jurisdiction_test' => [
                'has_legal_basis' => true,
                'employee_covered' => true,
                'within_municipal_jurisdiction' => true,
                'committee_decides' => true,
                'final_approval_authority' => 'عميد البلدية',
                'requires_central_approval' => false,
            ],
        ]);

        // Stage 78 gates the same hop on Appendix 63's بوابة 1 as well, for the
        // same reason: this file's scenarios are about the approval chain's
        // mechanics, not about the intake gate (which has its own coverage in
        // ControlGateTest).
        return $this->passIntakeGate($requestRecord);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }

    private function signature(): UploadedFile
    {
        return UploadedFile::fake()->image('signature.png', 960, 330);
    }
}
