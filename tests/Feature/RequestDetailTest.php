<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStageLog;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_role_appropriate_actor_can_read_the_detail_and_advance_it(): void
    {
        $this->seed(DatabaseSeeder::class);
        // Diagram-alignment redesign (see AGENT_NOTES.md): stage one's only
        // outbound action is now `submit` (manager-gated hops follow), which
        // isn't this test's subject. Start past the new front-half stages,
        // at reviewer_review, so this stays a plain role-gated `forward`
        // advance with no signature involved (that's `approve`'s concern,
        // covered by the approval-chain tests).
        $requestRecord = $this->newRequest('reviewer_review', 'in_review');
        $reviewer = $this->userWithRole('R02');

        Attachment::create([
            'request_id' => $requestRecord->id,
            'disk' => 'local',
            'path' => "attachments/{$requestRecord->id}/support.pdf",
            'original_name' => 'support.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);
        RequestStageLog::create([
            'request_id' => $requestRecord->id,
            'to_stage_id' => $requestRecord->current_stage_id,
            'action' => 'intake',
            'acted_by_user_id' => $reviewer->id,
            'acted_at' => now(),
        ]);

        $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $requestRecord->id)
            ->assertJsonPath('data.attachments.0.original_name', 'support.pdf')
            ->assertJsonPath('data.timeline.0.action', 'intake')
            ->assertJsonPath('data.available_actions.0', 'forward');

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", [
                'action' => 'forward',
                'comment' => 'تمت الإحالة للمراجعة.',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'observations')
            ->assertJsonPath('data.status.code', 'in_review')
            ->assertJsonPath('data.available_actions.0', 'forward');

        $this->assertDatabaseHas('request_stage_logs', [
            'request_id' => $requestRecord->id,
            'action' => 'forward',
            'acted_by_user_id' => $reviewer->id,
        ]);
    }

    public function test_an_actor_without_the_configured_role_cannot_transition_from_the_detail_endpoint(): void
    {
        $this->seed(DatabaseSeeder::class);
        $requestRecord = $this->newRequest();
        $employee = $this->userWithRole('R01');

        $this->actingAs($employee, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", ['action' => 'forward'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');

        $requestRecord->refresh();
        $this->assertSame('receive_from_municipality', $requestRecord->currentStage->code);
        $this->assertDatabaseCount('request_stage_logs', 0);
    }

    public function test_detail_exposes_exception_metadata_and_endpoint_preserves_the_required_reason(): void
    {
        $this->seed(DatabaseSeeder::class);
        $requestRecord = $this->newRequest('requirements_check', 'in_review');
        $reviewer = $this->userWithRole('R02');

        $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonFragment([
                'action' => 'return_missing_docs',
                'is_exception' => true,
                'requires_comment' => true,
            ])
            ->assertJsonFragment([
                'action' => 'cancel',
                'is_exception' => true,
                'requires_comment' => true,
            ]);

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", [
                'action' => 'return_missing_docs',
                'comment' => 'صورة المستند المطلوبة غير مرفقة.',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'receive_from_municipality')
            ->assertJsonPath('data.status.code', 'incomplete')
            ->assertJsonPath('data.timeline.0.action', 'return_missing_docs')
            ->assertJsonPath('data.timeline.0.comment', 'صورة المستند المطلوبة غير مرفقة.');
    }

    public function test_exception_endpoint_rejects_a_blank_reason(): void
    {
        $this->seed(DatabaseSeeder::class);
        $requestRecord = $this->newRequest('requirements_check', 'in_review');
        $reviewer = $this->userWithRole('R02');

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", [
                'action' => 'return_missing_docs',
                'comment' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');

        $requestRecord->refresh();
        $this->assertSame('requirements_check', $requestRecord->currentStage->code);
        $this->assertDatabaseCount('request_stage_logs', 0);
    }

    private function newRequest(string $stageCode = 'receive_from_municipality', string $statusCode = 'new'): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب تفصيلية',
            'description' => 'تفاصيل الطلب لاختبار شاشة العمل.',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
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
