<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Workflow designer — slice 2. The read side: the designer screen lists
 * workflows and draws a version, and a request page draws the version that
 * request runs on — never a newer one published after it was filed.
 */
class WorkflowViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_admin_lists_workflows_with_their_versions(): void
    {
        $published = Workflow::standard()->publishedVersion;

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/workflows')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'standard')
            ->assertJsonPath('data.0.versions.0.id', $published->id)
            ->assertJsonPath('data.0.versions.0.status', 'published')
            ->assertJsonPath('data.0.versions.0.stages_count', $published->stages()->count());
    }

    public function test_a_role_without_the_screen_is_refused(): void
    {
        $employee = $this->userWithRole('R01');
        $version = Workflow::standard()->publishedVersion;

        $this->actingAs($employee, 'sanctum')->getJson('/api/workflows')->assertForbidden();
        $this->actingAs($employee, 'sanctum')->getJson("/api/workflow-versions/{$version->id}")->assertForbidden();
    }

    public function test_a_version_carries_its_stages_and_every_rule_between_them(): void
    {
        $version = Workflow::standard()->publishedVersion;

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/workflow-versions/{$version->id}")
            ->assertOk()
            ->assertJsonPath('data.workflow.code', 'standard')
            ->assertJsonCount($version->stages()->count(), 'data.stages')
            ->assertJsonCount($version->transitions()->count(), 'data.transitions');

        $stageIds = collect($response->json('data.stages'))->pluck('id');
        foreach ($response->json('data.transitions') as $rule) {
            $this->assertTrue($stageIds->contains($rule['from_stage_id']));
            $this->assertTrue($stageIds->contains($rule['to_stage_id']));
        }

        // The manager's one-click approve is the rule the diagram must show as
        // belonging to «الرئيس المباشر», not to a role.
        $managerForward = collect($response->json('data.transitions'))->first(
            fn ($rule) => $rule['from_stage_id'] === $version->stage('direct_manager_review')->id && $rule['action'] === 'forward',
        );
        $this->assertTrue($managerForward['requires_submitter_manager']);
        $this->assertNull($managerForward['required_role']);
    }

    public function test_a_request_shows_the_version_it_runs_on(): void
    {
        $first = Workflow::standard()->publishedVersion;
        $filer = $this->userWithRole('R01');
        $requestRecord = $this->requestAt($first, 'requirements_check', $filer);
        $this->publishClone($first);

        $this->actingAs($filer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}/workflow")
            ->assertOk()
            ->assertJsonPath('data.current_stage_id', $requestRecord->current_stage_id)
            ->assertJsonPath('data.version.id', $first->id)
            ->assertJsonPath('data.version.status', 'retired');
    }

    public function test_a_stranger_cannot_see_a_requests_workflow(): void
    {
        $requestRecord = $this->requestAt(Workflow::standard()->publishedVersion, 'requirements_check', $this->userWithRole('R01'));

        $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}/workflow")
            ->assertNotFound();
    }

    private function publishClone(WorkflowVersion $from): WorkflowVersion
    {
        $draft = $from->cloneAsDraft();
        $from->update(['status' => 'retired']);
        $draft->update(['status' => 'published', 'published_at' => now()]);

        return $draft;
    }

    private function requestAt(WorkflowVersion $version, string $stageCode, User $filer): Request
    {
        return Request::create([
            'title' => 'ملف لعرض مساره',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_review')->value('id'),
            'current_stage_id' => $version->stage($stageCode)->id,
            'created_by_user_id' => $filer->id,
            'submitted_at' => now(),
        ]);
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
