<?php

namespace Tests\Feature;

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

/** Stage 52 — the non-blocking, per-stage soft-SLA escalation indicator. */
class StageTimelinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_just_arrived_at_a_targeted_stage_reads_green(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        // requirements_check: target_days_min/max = 3/3.
        $requestRecord = $this->requestAtStage($employee, 'requirements_check', 'in_review', now());

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.stage_timeliness.level', 'green')
            ->assertJsonPath('data.stage_timeliness.elapsed_days', 0)
            ->assertJsonPath('data.stage_timeliness.target_days_min', 3)
            ->assertJsonPath('data.stage_timeliness.target_days_max', 3);
    }

    public function test_a_request_past_target_but_within_one_and_a_half_times_reads_yellow(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        // 4 days elapsed against a target of 3: ratio 1.33.
        $requestRecord = $this->requestAtStage($employee, 'requirements_check', 'in_review', now()->subDays(4));

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.stage_timeliness.level', 'yellow');
    }

    public function test_a_request_past_one_and_a_half_times_target_reads_red(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        // 5 days elapsed against a target of 3: ratio 1.67.
        $requestRecord = $this->requestAtStage($employee, 'requirements_check', 'in_review', now()->subDays(5));

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.stage_timeliness.level', 'red');
    }

    public function test_a_request_past_double_target_reads_critical(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        // 7 days elapsed against a target of 3: ratio 2.33.
        $requestRecord = $this->requestAtStage($employee, 'requirements_check', 'in_review', now()->subDays(7));

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.stage_timeliness.level', 'critical');
    }

    public function test_a_stage_with_no_sourced_target_reports_no_indicator(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        // receive_from_municipality has no target_days_* seeded.
        $requestRecord = $this->requestAtStage($employee, 'receive_from_municipality', 'new', now()->subDays(30));

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.stage_timeliness', null);
    }

    public function test_the_field_round_trips_through_the_list_endpoint_too(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        $this->requestAtStage($employee, 'requirements_check', 'in_review', now()->subDays(4));

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/requests')
            ->assertOk()
            ->assertJsonPath('data.0.stage_timeliness.level', 'yellow');
    }

    private function requestAtStage(User $owner, string $stageCode, string $statusCode, \DateTimeInterface $enteredAt): Request
    {
        $stage = WorkflowStage::where('code', $stageCode)->firstOrFail();

        $requestRecord = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب موظف',
            'description' => 'طلب لاختبار مؤشر المدد التشغيلية المستهدفة.',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => $stage->id,
            'created_by_user_id' => $owner->id,
            'submitted_at' => now(),
        ]);

        RequestStageLog::create([
            'request_id' => $requestRecord->id,
            'to_stage_id' => $stage->id,
            'action' => 'test-setup',
            'acted_by_user_id' => $owner->id,
            'acted_at' => $enteredAt,
        ]);

        return $requestRecord;
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
