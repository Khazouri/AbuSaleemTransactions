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
use Tests\TestCase;

/**
 * Stage 93 — one stage count, everywhere an employee can see one.
 *
 * [F]'s ten steps and [G]'s own «1 من 11» disagree with each other, and
 * neither poster is committed to this repo to check against (Track M's own
 * warning in STAGE_PLAN.md). The denominator this stage settles on is the
 * system's own twelve `workflow_stages` rows — the only one of the three
 * actually verifiable from this codebase — read live via WorkflowStage::
 * count() rather than hard-coded, so these assertions still hold if a future
 * stage adds or removes one.
 *
 * The property under test is not "a number exists" but that every consumer
 * of RequestResource — the request workspace, the work queue, the reports
 * table (both its rows and its shared KPI summary) and the dashboard — report
 * the identical total, since two screens disagreeing about it is exactly the
 * inconsistency this stage exists to close.
 */
class StageProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_request_workspace_reports_the_current_stage_over_the_seeded_total(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        $stage = WorkflowStage::where('code', 'requirements_check')->firstOrFail();
        $requestRecord = $this->requestAtStage($employee, $stage->code);

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.stage_progress.current', $stage->order_no)
            ->assertJsonPath('data.stage_progress.total', WorkflowStage::count());
    }

    public function test_the_field_round_trips_through_the_work_queue_too(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        $this->requestAtStage($employee, 'requirements_check');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/requests')
            ->assertOk()
            ->assertJsonPath('data.0.stage_progress.total', WorkflowStage::count());
    }

    public function test_the_reports_table_agrees_with_its_own_kpi_summary(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        $this->requestAtStage($employee, 'requirements_check');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/reports/requests')
            ->assertOk()
            ->assertJsonPath('data.0.stage_progress.total', WorkflowStage::count())
            ->assertJsonPath('summary.total_stages', WorkflowStage::count());
    }

    public function test_the_dashboard_reports_the_same_total_as_the_per_request_denominator(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        $this->requestAtStage($employee, 'requirements_check');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kpis.total_stages', WorkflowStage::count());
    }

    public function test_a_request_with_no_current_stage_reports_no_progress(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        $requestRecord = $this->requestAtStage($employee, 'requirements_check');
        $requestRecord->forceFill(['current_stage_id' => null])->save();

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.stage_progress', null);
    }

    private function requestAtStage(User $owner, string $stageCode): Request
    {
        $stage = WorkflowStage::where('code', $stageCode)->firstOrFail();

        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب موظف',
            'description' => 'طلب لاختبار مؤشر تقدم المرحلة.',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_review')->value('id'),
            'current_stage_id' => $stage->id,
            'created_by_user_id' => $owner->id,
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
