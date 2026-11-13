<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RequestSlaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_overdue_sweep_backfills_type_deadlines_flags_open_work_and_skips_closed_work(): void
    {
        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow('2026-01-20 08:00:00');

        $open = $this->request('requirements_check', 'in_review', '2026-01-01 10:00:00');
        $closed = $this->request('final_approval_archiving', 'archived', '2026-01-01 10:00:00');

        $this->artisan('requests:flag-overdue')
            ->expectsOutput('Backfilled 2 deadline(s); flagged 1 overdue request(s).')
            ->assertExitCode(0);

        $open->refresh();
        $closed->refresh();

        $this->assertSame('2026-01-16', $open->due_date?->toDateString());
        $this->assertNotNull($open->overdue_at);
        $this->assertTrue($open->isOverdue());
        $this->assertSame('2026-01-16', $closed->due_date?->toDateString());
        $this->assertNull($closed->overdue_at);
    }

    /**
     * High open items (2026-11-13) — overdue is a flag on the dashboard and
     * in reports, not a move (user decision). R08's escalation sent every
     * late file to الوزير's stage although no committee had sent it there.
     */
    public function test_an_overdue_file_is_flagged_but_not_escalated(): void
    {
        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow('2026-01-20 08:00:00');
        $requestRecord = $this->request('requirements_check', 'in_review', '2026-01-01 10:00:00');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();

        $this->artisan('requests:flag-overdue')->assertExitCode(0);

        $this->assertTrue($requestRecord->refresh()->isOverdue());
        $this->assertNotContains('deadline_expired', app(WorkflowService::class)->availableActions($requestRecord, $admin));
        $this->assertSame(0, WorkflowTransition::where('action', 'deadline_expired')->count());
    }

    /**
     * A version copied before the removal keeps its `deadline_expired` rows,
     * which the seeder (Standard v1 only) does not clean. The engine refuses
     * the action by name, so such a row can never move a file.
     */
    public function test_a_leftover_escalation_rule_is_never_offered_or_applied(): void
    {
        $this->seed(DatabaseSeeder::class);
        $requestRecord = $this->request('requirements_check', 'in_review', now()->toDateTimeString());
        $requestRecord->forceFill(['overdue_at' => now()])->save();
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        WorkflowTransition::create([
            'from_stage_id' => $requestRecord->current_stage_id,
            'to_stage_id' => WorkflowStage::where('code', 'local_governance_ministry')->value('id'),
            'action' => 'deadline_expired',
            'required_role_id' => Role::where('code', 'R08')->value('id'),
            'set_status_id' => RequestStatus::where('code', 'in_review')->value('id'),
            'is_exception' => true,
            'requires_comment' => true,
            'order_no' => 90,
        ]);
        $service = app(WorkflowService::class);

        $this->assertNotContains('deadline_expired', $service->availableActions($requestRecord->refresh(), $admin));

        $this->expectException(WorkflowTransitionException::class);
        $service->transition($requestRecord, 'deadline_expired', $admin, 'انتهت المهلة.');
    }

    private function request(string $stageCode, string $status, string $submittedAt): Request
    {
        return Request::create([
            'reference_number' => '2026-ADM-'.fake()->unique()->numerify('######'),
            'title' => 'اختبار مهلة الإنجاز',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $status)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'submitted_at' => Carbon::parse($submittedAt),
        ]);
    }
}
