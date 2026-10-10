<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use App\Models\WorkflowVersion;
use App\Services\ReportMetricsService;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\PassesControlGates;
use Tests\TestCase;

/**
 * Workflow designer — slice 3, spec §1's success case. A promotion type is
 * bound to its own workflow, whose published version puts «تدقيق مالي»
 * (R05) between the manager and HR. A new promotion stops there until R05
 * acts; a promotion filed before the publish, and every other type, carry
 * on as they did.
 */
class WorkflowDraftRuntimeTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $this->manager = $this->userWithRole('R01');
    }

    public function test_a_new_request_of_a_bound_type_passes_the_custom_step_and_others_do_not(): void
    {
        $promotion = RequestType::where('code', 'PROM')->firstOrFail();
        $leave = RequestType::where('code', 'LEAV')->firstOrFail();
        $before = $this->file($promotion);

        $version = $this->publishFinanceWorkflow();
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/request-types/{$promotion->id}", ['workflow_id' => $version->workflow_id])
            ->assertOk()
            ->assertJsonPath('data.workflow_id', $version->workflow_id);

        $after = $this->file($promotion);
        $other = $this->file($leave);
        $workflow = app(WorkflowService::class);

        $moved = $workflow->transition($after, 'forward', $this->manager);
        $this->assertSame('تدقيق مالي', $moved->currentStage->name_ar);
        $this->assertSame($version->id, $moved->currentStage->workflow_version_id);

        $auditor = $this->userWithRole('R05');
        $audit = $workflow->availableTransitions($moved, $auditor)->sole();
        $this->assertStringStartsWith(WorkflowTransition::CUSTOM_PREFIX, $audit->action);
        $done = $workflow->transition($moved, $audit->action, $auditor);
        $this->assertSame('receive_and_register', $done->currentStage->code);
        $this->assertSame('routed_to_hr', $done->status->code);

        $this->assertSame('receive_and_register', $workflow->transition($before, 'forward', $this->manager)->currentStage->code);
        $this->assertSame('receive_and_register', $workflow->transition($other, 'forward', $this->manager)->currentStage->code);
        $this->assertSame(Workflow::standard()->publishedVersion->id, $other->fresh()->currentStage->workflow_version_id);
    }

    public function test_a_type_cannot_be_bound_to_a_workflow_with_nothing_published(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/workflows', ['code' => 'unpublished', 'name_ar' => 'غير منشور', 'from_version_id' => Workflow::standard()->publishedVersion->id])
            ->assertCreated();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/request-types/'.RequestType::where('code', 'PROM')->value('id'), ['workflow_id' => Workflow::where('code', 'unpublished')->value('id')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('workflow_id');
    }

    public function test_a_file_at_a_custom_step_names_its_role_its_action_and_its_label(): void
    {
        $promotion = RequestType::where('code', 'PROM')->firstOrFail();
        $version = $this->publishFinanceWorkflow();
        $promotion->update(['workflow_id' => $version->workflow_id]);
        $atAudit = app(WorkflowService::class)->transition($this->file($promotion), 'forward', $this->manager);
        $auditor = $this->userWithRole('R05');

        $this->actingAs($auditor, 'sanctum')->getJson("/api/requests/{$atAudit->id}")
            ->assertOk()
            ->assertJsonPath('data.responsibility.responsible.code', 'role')
            ->assertJsonPath('data.responsibility.responsible.names', [Role::where('code', 'R05')->value('name_ar')])
            ->assertJsonPath('data.responsibility.next_action.code', 'complete_step')
            ->assertJsonPath('data.available_transitions.0.label_ar', 'اعتماد التدقيق')
            ->assertJsonPath('data.stage_progress.total', $version->stages()->count());

        $tasks = collect($this->actingAs($auditor, 'sanctum')->getJson('/api/my-tasks')->assertOk()->json('data.sources'))
            ->flatMap(fn ($group) => $group['tasks']);
        $this->assertContains('custom_step', $tasks->pluck('action')->all());

        $done = app(WorkflowService::class)->transition($atAudit, 'custom_audit_done', $auditor);
        $timeline = collect($this->actingAs(User::findOrFail($done->created_by_user_id), 'sanctum')->getJson("/api/requests/{$done->id}")->assertOk()->json('data.timeline'));
        $this->assertSame('اعتماد التدقيق', $timeline->firstWhere('action', 'custom_audit_done')['label_ar']);
    }

    public function test_the_stage_report_groups_one_code_across_versions(): void
    {
        $promotion = RequestType::where('code', 'PROM')->firstOrFail();
        $this->file($promotion);
        $version = $this->publishFinanceWorkflow();
        // A custom step inserted before it renumbers a built-in in the new version.
        $version->stage('direct_manager_review')->update(['order_no' => 50]);
        $promotion->update(['workflow_id' => $version->workflow_id]);
        $this->file($promotion);

        $managerReview = collect(app(ReportMetricsService::class)->breakdowns([])['by_stage'])
            ->where('code', 'direct_manager_review');
        $this->assertCount(1, $managerReview);
        $this->assertSame(2, $managerReview->first()['total']);
    }

    /** A new «مسار مالي» workflow: Standard plus «تدقيق مالي» after the manager, published. */
    private function publishFinanceWorkflow(): WorkflowVersion
    {
        $admin = $this->actingAs($this->admin(), 'sanctum');
        $id = $admin->postJson('/api/workflows', ['code' => 'finance', 'name_ar' => 'مسار مالي', 'from_version_id' => Workflow::standard()->publishedVersion->id])
            ->assertCreated()->json('data.id');
        $draft = WorkflowVersion::findOrFail($id);

        $audit = WorkflowStage::create(['workflow_version_id' => $draft->id, 'code' => 'custom_audit', 'order_no' => 99, 'name_ar' => 'تدقيق مالي']);
        WorkflowTransition::query()->where('from_stage_id', $draft->stage('direct_manager_review')->id)->where('action', 'forward')
            ->update(['to_stage_id' => $audit->id]);
        WorkflowTransition::create([
            'from_stage_id' => $audit->id,
            'to_stage_id' => $draft->stage('receive_and_register')->id,
            'action' => 'custom_audit_done',
            'label_ar' => 'اعتماد التدقيق',
            'required_role_id' => Role::where('code', 'R05')->value('id'),
        ]);

        $admin->postJson("/api/workflow-versions/{$draft->id}/publish")->assertOk();

        return $draft->fresh();
    }

    /** A request filed through intake by an employee whose manager is $this->manager. */
    private function file(RequestType $type): Request
    {
        $filer = User::factory()->create([
            'is_active' => true,
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'manager_id' => $this->manager->id,
        ]);
        $filer->roles()->attach(Role::where('code', 'R01')->value('id'));

        $id = $this->actingAs($filer, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب '.$type->code,
                'request_type_id' => $type->id,
                'decision_grade' => 9,
                'entitled_grade' => 9,
                'grade_date' => '2024-03-01',
                'attachments' => $this->mandatoryAttachments($type),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        return Request::findOrFail($id);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@abusaleem.test')->firstOrFail();
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
