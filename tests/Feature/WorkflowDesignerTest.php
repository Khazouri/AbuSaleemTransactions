<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowVersion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Workflow designer — slice 3. The draft lifecycle over HTTP: copy a
 * version, edit the copy as a whole graph, publish it in one swap, or throw
 * it away — and none of it ever reaches a version requests run on.
 */
class WorkflowDesignerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_admin_clones_the_published_version_into_one_draft(): void
    {
        $published = Workflow::standard()->publishedVersion;

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/workflow-versions/{$published->id}/clone")
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.number', 2)
            ->assertJsonPath('data.report.errors', [])
            ->assertJsonCount($published->stages()->count(), 'data.stages');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/workflow-versions/{$published->id}/clone")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('version');
    }

    public function test_a_role_without_the_screen_cannot_write(): void
    {
        $published = Workflow::standard()->publishedVersion;
        $draft = $published->cloneAsDraft();
        $employee = $this->userWithRole('R01');

        $this->actingAs($employee, 'sanctum')->postJson("/api/workflow-versions/{$published->id}/clone")->assertForbidden();
        $this->actingAs($employee, 'sanctum')->putJson("/api/workflow-versions/{$draft->id}", $this->payload($draft))->assertForbidden();
        $this->actingAs($employee, 'sanctum')->postJson("/api/workflow-versions/{$draft->id}/publish")->assertForbidden();
        $this->actingAs($employee, 'sanctum')->deleteJson("/api/workflow-versions/{$draft->id}")->assertForbidden();
        $this->actingAs($employee, 'sanctum')->postJson('/api/workflows', [])->assertForbidden();
    }

    public function test_a_custom_step_is_inserted_mid_chain_and_the_version_renumbered(): void
    {
        $draft = Workflow::standard()->publishedVersion->cloneAsDraft();
        $payload = $this->withAuditStep($this->payload($draft), $draft);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/workflow-versions/{$draft->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.report.errors', []);

        $stages = collect($response->json('data.stages'));
        $audit = $stages->firstWhere('name_ar', 'تدقيق مالي');
        $this->assertTrue($audit['custom']);
        $this->assertStringStartsWith('custom_', $audit['code']);
        $this->assertSame(3, $audit['order_no']);
        $this->assertSame(range(1, $stages->count()), $stages->pluck('order_no')->all());

        $forward = collect($response->json('data.transitions'))->first(fn ($rule) => $rule['action'] === 'forward'
            && $rule['from_stage_id'] === $stages->firstWhere('code', 'direct_manager_review')['id']);
        $this->assertSame($audit['id'], $forward['to_stage_id']);
        $outcome = collect($response->json('data.transitions'))->firstWhere('label_ar', 'اعتماد التدقيق');
        $this->assertStringStartsWith('custom_', $outcome['action']);
        $this->assertTrue($outcome['custom']);
    }

    public function test_built_in_steps_and_rules_are_locked(): void
    {
        $draft = Workflow::standard()->publishedVersion->cloneAsDraft();
        $put = fn (array $payload) => $this->actingAs($this->admin(), 'sanctum')->putJson("/api/workflow-versions/{$draft->id}", $payload);

        $payload = $this->payload($draft);
        $payload['stages'] = array_values(array_filter($payload['stages'], fn ($stage) => $stage['code'] !== 'requirements_check'));
        $put($payload)->assertUnprocessable()->assertJsonValidationErrors('stages');

        $payload = $this->payload($draft);
        $payload['transitions'] = array_values(array_filter($payload['transitions'], fn ($rule) => $rule['to'] !== 'requirements_check' || $rule['from'] === 'requirements_check'));
        $put($payload)->assertUnprocessable()->assertJsonValidationErrors('transitions');

        $payload = $this->payload($draft);
        $index = collect($payload['transitions'])->search(fn ($rule) => $rule['from'] === 'receive_and_register' && $rule['to'] === 'requirements_check');
        $payload['transitions'][$index]['from'] = 'direct_manager_review';
        $put($payload)->assertUnprocessable()->assertJsonValidationErrors("transitions.{$index}.from");
    }

    public function test_a_put_cannot_touch_a_rule_of_another_version(): void
    {
        $published = Workflow::standard()->publishedVersion;
        $draft = $published->cloneAsDraft();
        $payload = $this->payload($draft);
        $payload['transitions'][] = [
            ...$payload['transitions'][0],
            'id' => $published->transitions()->first()->id,
        ];

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/workflow-versions/{$draft->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transitions');
    }

    public function test_a_custom_outcome_needs_an_arabic_label_and_an_actor(): void
    {
        $draft = Workflow::standard()->publishedVersion->cloneAsDraft();
        $payload = $this->withAuditStep($this->payload($draft), $draft);
        $last = count($payload['transitions']) - 1;
        $payload['transitions'][$last]['label_ar'] = '';
        $payload['transitions'][$last]['required_role_id'] = null;

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/workflow-versions/{$draft->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(["transitions.{$last}.label_ar", "transitions.{$last}.required_role_id"]);
    }

    public function test_an_outcome_to_a_removed_step_is_refused(): void
    {
        $draft = Workflow::standard()->publishedVersion->cloneAsDraft();
        $saved = $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/workflow-versions/{$draft->id}", $this->withAuditStep($this->payload($draft), $draft))
            ->assertOk();
        $code = collect($saved->json('data.stages'))->firstWhere('name_ar', 'تدقيق مالي')['code'];

        $payload = $this->payload($draft->fresh());
        $payload['stages'] = array_values(array_filter($payload['stages'], fn ($stage) => $stage['code'] !== $code));

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/workflow-versions/{$draft->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transitions');
        $this->assertTrue(WorkflowStage::query()->where('code', $code)->exists());
    }

    public function test_publish_refuses_a_draft_with_errors_and_swaps_a_clean_one(): void
    {
        $first = Workflow::standard()->publishedVersion;
        $draft = $first->cloneAsDraft();
        $island = WorkflowStage::create(['workflow_version_id' => $draft->id, 'code' => 'custom_island', 'order_no' => 99, 'name_ar' => 'جزيرة']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/workflow-versions/{$draft->id}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('report.errors.0.code', 'unreachable');
        $this->assertSame('published', $first->fresh()->status);

        $island->delete();
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/workflow-versions/{$draft->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
        $this->assertSame('retired', $first->fresh()->status);
        $this->assertSame($this->admin()->id, $draft->fresh()->published_by_user_id);
        $this->assertSame($draft->id, Workflow::standard()->publishedVersion->id);
    }

    public function test_only_a_draft_is_edited_or_deleted(): void
    {
        $published = Workflow::standard()->publishedVersion;
        $draft = $published->cloneAsDraft();

        $this->actingAs($this->admin(), 'sanctum')->putJson("/api/workflow-versions/{$published->id}", $this->payload($published))
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->actingAs($this->admin(), 'sanctum')->deleteJson("/api/workflow-versions/{$published->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('version');

        $this->actingAs($this->admin(), 'sanctum')->deleteJson("/api/workflow-versions/{$draft->id}")->assertNoContent();
        $this->assertNull(WorkflowVersion::find($draft->id));
        $this->assertSame(0, WorkflowStage::query()->where('workflow_version_id', $draft->id)->count());
    }

    public function test_a_new_workflow_starts_as_a_draft_copy_of_a_version(): void
    {
        $published = Workflow::standard()->publishedVersion;

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/workflows', ['code' => 'finance', 'name_ar' => 'مسار مالي', 'from_version_id' => $published->id])
            ->assertCreated()
            ->assertJsonPath('data.workflow.code', 'finance')
            ->assertJsonPath('data.number', 1)
            ->assertJsonPath('data.status', 'draft');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/workflows', ['code' => 'finance', 'name_ar' => 'مكرر', 'from_version_id' => $published->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    /** The PUT body for $version as the SPA would send it, unchanged. */
    private function payload(WorkflowVersion $version): array
    {
        $stages = $version->stages()->orderBy('order_no')->get();
        $codes = $stages->pluck('code', 'id');

        return [
            'stages' => $stages->map(fn ($stage) => [
                'code' => $stage->code,
                'name_ar' => $stage->name_ar,
                'name_en' => $stage->name_en,
                'responsible_role_id' => $stage->responsible_role_id,
                'target_days_min' => $stage->target_days_min,
                'target_days_max' => $stage->target_days_max,
            ])->all(),
            'transitions' => $version->transitions()->get()->map(fn ($rule) => [
                'id' => $rule->id,
                'from' => $codes[$rule->from_stage_id],
                'to' => $codes[$rule->to_stage_id],
                'label_ar' => $rule->label_ar,
                'label_en' => $rule->label_en,
                'required_role_id' => $rule->required_role_id,
                'set_status_id' => $rule->set_status_id,
                'required_status_id' => $rule->required_status_id,
                'is_exception' => $rule->is_exception,
                'requires_comment' => $rule->requires_comment,
                'requires_submitter_manager' => $rule->requires_submitter_manager,
                'requires_creator' => $rule->requires_creator,
            ])->all(),
        ];
    }

    /**
     * «تدقيق مالي» after the manager's review, in two PUTs as the SPA does
     * it: the step first (its code is minted on save), then the manager's
     * forward redirected into it and its own outcome on to HR.
     */
    private function withAuditStep(array $payload, WorkflowVersion $draft): array
    {
        $at = collect($payload['stages'])->search(fn ($stage) => $stage['code'] === 'direct_manager_review');
        array_splice($payload['stages'], $at + 1, 0, [[
            'code' => null, 'name_ar' => 'تدقيق مالي', 'name_en' => 'Financial audit',
            'responsible_role_id' => Role::where('code', 'R05')->value('id'),
            'target_days_min' => 1, 'target_days_max' => 3,
        ]]);
        $code = collect($this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/workflow-versions/{$draft->id}", $payload)->assertOk()->json('data.stages'))
            ->firstWhere('name_ar', 'تدقيق مالي')['code'];

        $payload = $this->payload($draft->fresh());
        foreach ($payload['transitions'] as &$rule) {
            if ($rule['from'] === 'direct_manager_review' && $rule['to'] === 'receive_and_register') {
                $rule['to'] = $code;
            }
        }
        unset($rule);
        $payload['transitions'][] = [
            'id' => null, 'from' => $code, 'to' => 'receive_and_register',
            'label_ar' => 'اعتماد التدقيق', 'label_en' => null,
            'required_role_id' => Role::where('code', 'R05')->value('id'),
            'set_status_id' => null, 'required_status_id' => null,
            'is_exception' => false, 'requires_comment' => false,
            'requires_submitter_manager' => false, 'requires_creator' => false,
        ];

        return $payload;
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
