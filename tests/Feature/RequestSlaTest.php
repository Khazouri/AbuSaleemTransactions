<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowStage;
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

    public function test_only_flagged_overdue_work_exposes_and_executes_the_deadline_escalation_path(): void
    {
        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow('2026-01-20 08:00:00');
        $requestRecord = $this->request('requirements_check', 'in_review', '2026-01-01 10:00:00');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $service = app(WorkflowService::class);

        $this->assertNotContains('deadline_expired', $service->availableActions($requestRecord, $admin));

        $this->artisan('requests:flag-overdue')->assertExitCode(0);

        $this->assertContains('deadline_expired', $service->availableActions($requestRecord->refresh(), $admin));

        $requestRecord = $service->transition(
            $requestRecord,
            'deadline_expired',
            $admin,
            'انتهت المهلة النظامية للطلب.',
        );

        $this->assertSame('local_governance_ministry', $requestRecord->currentStage->code);
        $this->assertSame('in_review', $requestRecord->status->code);
        $this->assertDatabaseHas('request_stage_logs', [
            'request_id' => $requestRecord->id,
            'action' => 'deadline_expired',
            'comment' => 'انتهت المهلة النظامية للطلب.',
            'to_stage_id' => WorkflowStage::where('code', 'local_governance_ministry')->value('id'),
        ]);
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
