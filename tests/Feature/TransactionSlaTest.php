<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Transaction;
use App\Models\TransactionStatus;
use App\Models\TransactionType;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TransactionSlaTest extends TestCase
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

        $open = $this->transaction(3, 'in_review', '2026-01-01 10:00:00');
        $closed = $this->transaction(11, 'archived', '2026-01-01 10:00:00');

        $this->artisan('transactions:flag-overdue')
            ->expectsOutput('Backfilled 2 deadline(s); flagged 1 overdue transaction(s).')
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
        $transaction = $this->transaction(3, 'in_review', '2026-01-01 10:00:00');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $service = app(WorkflowService::class);

        $this->assertNotContains('deadline_expired', $service->availableActions($transaction, $admin));

        $this->artisan('transactions:flag-overdue')->assertExitCode(0);

        $this->assertContains('deadline_expired', $service->availableActions($transaction->refresh(), $admin));

        $transaction = $service->transition(
            $transaction,
            'deadline_expired',
            $admin,
            'انتهت المهلة النظامية للمعاملة.',
        );

        $this->assertSame(9, $transaction->currentStage->order_no);
        $this->assertSame('in_review', $transaction->status->code);
        $this->assertDatabaseHas('transaction_stage_logs', [
            'transaction_id' => $transaction->id,
            'action' => 'deadline_expired',
            'comment' => 'انتهت المهلة النظامية للمعاملة.',
            'to_stage_id' => WorkflowStage::where('order_no', 9)->value('id'),
        ]);
    }

    private function transaction(int $stage, string $status, string $submittedAt): Transaction
    {
        return Transaction::create([
            'reference_number' => '2026-ADM-'.fake()->unique()->numerify('######'),
            'title' => 'اختبار مهلة الإنجاز',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'transaction_type_id' => TransactionType::where('code', 'PROM')->value('id'),
            'status_id' => TransactionStatus::where('code', $status)->value('id'),
            'current_stage_id' => WorkflowStage::where('order_no', $stage)->value('id'),
            'submitted_at' => Carbon::parse($submittedAt),
        ]);
    }
}
