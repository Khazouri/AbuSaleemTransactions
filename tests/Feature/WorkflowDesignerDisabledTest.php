<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The workflow designer is an option, off unless WORKFLOW_DESIGNER_ENABLED
 * turns it on (user decision 2026-10-12). Off, the system reads as it did
 * before the designer: no screen, no request canvas, no workflow picker.
 */
class WorkflowDesignerDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['app.workflow_designer' => false]);
    }

    public function test_the_admin_loses_the_designer_screen(): void
    {
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();

        $this->assertFalse($admin->screenPermissions()['workflow_designer']['can_view'] ?? false);
        $this->actingAs($admin, 'sanctum')->getJson('/api/workflows')->assertForbidden();
    }

    public function test_a_request_shows_no_workflow_canvas(): void
    {
        $filer = User::factory()->create(['is_active' => true]);
        $filer->roles()->attach(Role::where('code', 'R01')->value('id'));
        $requestRecord = Request::create([
            'title' => 'ملف بلا مصمم',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_review')->value('id'),
            'current_stage_id' => Workflow::standard()->publishedVersion->stage('requirements_check')->id,
            'created_by_user_id' => $filer->id,
            'submitted_at' => now(),
        ]);

        $this->actingAs($filer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.workflow_view', false);
        $this->actingAs($filer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}/workflow")
            ->assertNotFound();
    }

    public function test_request_types_offer_no_workflow_and_a_new_type_runs_on_standard(): void
    {
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $custom = Workflow::standard()->publishedVersion->cloneAsDraft(
            Workflow::create(['code' => 'custom', 'name_ar' => 'مخصص', 'name_en' => 'Custom']),
        );
        $custom->update(['status' => 'published', 'published_at' => now()]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/request-types')
            ->assertOk()
            ->assertJsonPath('workflows', []);

        // A stale client posting a workflow cannot bind a type to it while off.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/request-types', [
                'code' => 'OFFW',
                'name_ar' => 'نوع والمصمم معطل',
                'name_en' => 'Type with the designer off',
                'default_sla_days' => 5,
                'workflow_id' => $custom->workflow_id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.workflow_id', Workflow::standard()->id);
    }
}
