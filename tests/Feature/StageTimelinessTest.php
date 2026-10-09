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

/**
 * Stage 52 — the non-blocking, per-stage soft-SLA escalation indicator.
 *
 * Stage 71 rewrote every expectation below, deliberately rather than as a
 * regression fix: the seeded targets now come from [D] Appendix 37 (so
 * requirements_check is 2 days, not 3), and Appendix 38's buckets mean
 * something different from Stage 52's invented ratio scheme — أصفر is now
 * "قرب تجاوز المدة", i.e. still WITHIN the target, where the old yellow began
 * only after it had been exceeded. حرج likewise stops being "twice the
 * target" and becomes the source's own qualitative condition.
 */
class StageTimelinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_just_arrived_at_a_targeted_stage_reads_green(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        // requirements_check: Appendix 37's فحص المقرر = يومان.
        $requestRecord = $this->requestAtStage($employee, 'requirements_check', 'in_review', now());

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.stage_timeliness.level', 'green')
            ->assertJsonPath('data.stage_timeliness.elapsed_days', 0)
            ->assertJsonPath('data.stage_timeliness.target_days_min', 2)
            ->assertJsonPath('data.stage_timeliness.target_days_max', 2)
            ->assertJsonPath('data.stage_timeliness.escalation', null);
    }

    public function test_the_final_day_of_the_allowance_reads_yellow_before_the_target_is_exceeded(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        // 2 days elapsed against a 2-day target: the allowance is fully
        // consumed but not yet exceeded — Appendix 38's قرب تجاوز المدة.
        $requestRecord = $this->requestAtStage($employee, 'requirements_check', 'in_review', now()->subDays(2));

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.stage_timeliness.level', 'yellow');
    }

    public function test_a_request_past_the_target_reads_red(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        // One day past the 2-day target — متأخرة, with no further gradation.
        $requestRecord = $this->requestAtStage($employee, 'requirements_check', 'in_review', now()->subDays(3));

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.stage_timeliness.level', 'red');
    }

    public function test_being_far_past_the_target_is_still_only_red_without_a_legal_deadline(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        // Under Stage 52's ratio scheme 7 days against a 3-day target read
        // `critical`. Appendix 38's حرج is a condition, not more elapsed time.
        $requestRecord = $this->requestAtStage($employee, 'requirements_check', 'in_review', now()->subDays(30));

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.stage_timeliness.level', 'red');
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
        $this->requestAtStage($employee, 'requirements_check', 'in_review', now()->subDays(2));

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
