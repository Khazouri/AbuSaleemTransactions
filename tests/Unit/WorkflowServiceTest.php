<?php

namespace Tests\Unit;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Department;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionStatus;
use App\Models\TransactionType;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_happy_path_moves_a_transaction_from_stage_one_to_eleven_with_complete_logs(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actors = collect(['R02', 'R03', 'R05', 'R06', 'R07'])
            ->mapWithKeys(fn (string $roleCode) => [
                $roleCode => $this->userWithRole($roleCode),
            ]);

        $transaction = $this->newTransaction();
        $service = app(WorkflowService::class);

        $steps = [
            [1, 2, 'forward', 'R02', 'in_review'],
            [2, 3, 'approve', 'R02', 'in_review'],
            [3, 4, 'forward', 'R02', 'in_review'],
            [4, 5, 'forward', 'R02', 'ready'],
            [5, 6, 'approve', 'R05', 'ready'],
            [6, 7, 'forward', 'R05', 'in_meeting'],
            [7, 8, 'forward', 'R03', 'decided'],
            [8, 9, 'approve', 'R03', 'approved'],
            [9, 10, 'approve', 'R06', 'final_approved'],
            [10, 11, 'approve', 'R07', 'archived'],
        ];

        foreach ($steps as [$from, $to, $action, $roleCode, $statusCode]) {
            $transaction = $service->transition($transaction, $action, $actors[$roleCode]);

            $this->assertSame($to, $transaction->currentStage->order_no);
            $this->assertSame($statusCode, $transaction->status->code);
            $this->assertDatabaseHas('transaction_stage_logs', [
                'transaction_id' => $transaction->id,
                'from_stage_id' => WorkflowStage::where('order_no', $from)->value('id'),
                'to_stage_id' => WorkflowStage::where('order_no', $to)->value('id'),
                'action' => $action,
                'acted_by_user_id' => $actors[$roleCode]->id,
            ]);
        }

        $this->assertSame(11, $transaction->currentStage->order_no);
        $this->assertSame('archived', $transaction->status->code);
        $this->assertCount(10, $transaction->stageLogs);
        $this->assertCount(10, $transaction->statusHistory);
        $this->assertSame(
            range(2, 11),
            $transaction->stageLogs()
                ->with('toStage')
                ->orderBy('id')
                ->get()
                ->pluck('toStage.order_no')
                ->all(),
        );
    }

    public function test_transition_rejects_an_actor_without_the_configured_role_without_mutating_state(): void
    {
        $this->seed(DatabaseSeeder::class);

        $transaction = $this->newTransaction();
        $employee = $this->userWithRole('R01');

        try {
            app(WorkflowService::class)->transition($transaction, 'forward', $employee);
            $this->fail('The transition should reject an actor without R02.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame(
                'لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.',
                $exception->getMessage(),
            );
        }

        $transaction->refresh();

        $this->assertSame(1, $transaction->currentStage->order_no);
        $this->assertSame('new', $transaction->status->code);
        $this->assertDatabaseCount('transaction_stage_logs', 0);
        $this->assertDatabaseCount('transaction_status_history', 0);
    }

    public function test_seeded_happy_path_contains_one_ordered_rule_for_each_forward_stage(): void
    {
        $this->seed(DatabaseSeeder::class);

        $rules = WorkflowTransition::query()
            ->where('is_exception', false)
            ->with(['fromStage', 'toStage', 'requiredRole'])
            ->orderBy('order_no')
            ->get();

        $this->assertCount(10, $rules);
        $this->assertSame(range(1, 10), $rules->pluck('fromStage.order_no')->all());
        $this->assertSame(range(2, 11), $rules->pluck('toStage.order_no')->all());
        $this->assertFalse($rules->contains('is_exception', true));
        $this->assertFalse($rules->contains('requires_comment', true));
    }

    public function test_each_seeded_exception_path_moves_to_the_expected_stage_status_and_records_its_reason(): void
    {
        $this->seed(DatabaseSeeder::class);

        $reviewer = $this->userWithRole('R02');
        $adminManager = $this->userWithRole('R05');
        $service = app(WorkflowService::class);
        $paths = [
            [2, 'return_missing_docs', $reviewer, 1, 'incomplete', 'المستند المالي غير مرفق.'],
            [3, 'reject_review', $reviewer, 2, 'rejected', 'المعاملة لا تطابق اللائحة.'],
            [4, 'request_edit', $reviewer, 3, 'returned', 'يرجى تصحيح بيانات القرار.'],
            [6, 'cancel', $adminManager, 6, 'cancelled', 'ألغيت المعاملة بناءً على كتاب رسمي.'],
        ];

        foreach ($paths as [$from, $action, $actor, $to, $status, $reason]) {
            $transaction = $this->newTransaction($from, 'in_review');
            $transaction = $service->transition($transaction, $action, $actor, $reason);

            $this->assertSame($to, $transaction->currentStage->order_no);
            $this->assertSame($status, $transaction->status->code);
            $this->assertDatabaseHas('transaction_stage_logs', [
                'transaction_id' => $transaction->id,
                'from_stage_id' => WorkflowStage::where('order_no', $from)->value('id'),
                'to_stage_id' => WorkflowStage::where('order_no', $to)->value('id'),
                'action' => $action,
                'comment' => $reason,
                'acted_by_user_id' => $actor->id,
            ]);
            $this->assertDatabaseHas('transaction_status_history', [
                'transaction_id' => $transaction->id,
                'to_status_id' => TransactionStatus::where('code', $status)->value('id'),
                'reason' => $reason,
                'changed_by_user_id' => $actor->id,
            ]);
        }
    }

    public function test_exception_requires_a_non_blank_reason_without_mutating_the_transaction(): void
    {
        $this->seed(DatabaseSeeder::class);

        $transaction = $this->newTransaction(2, 'in_review');
        $reviewer = $this->userWithRole('R02');

        try {
            app(WorkflowService::class)->transition($transaction, 'return_missing_docs', $reviewer, '   ');
            $this->fail('The exception transition should require a reason.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('يجب إدخال سبب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        $transaction->refresh();
        $this->assertSame(2, $transaction->currentStage->order_no);
        $this->assertSame('in_review', $transaction->status->code);
        $this->assertDatabaseCount('transaction_stage_logs', 0);
        $this->assertDatabaseCount('transaction_status_history', 0);
    }

    public function test_cancelled_transaction_is_terminal_and_exposes_no_further_actions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $reviewer = $this->userWithRole('R02');
        $service = app(WorkflowService::class);
        $transaction = $service->transition(
            $this->newTransaction(2, 'in_review'),
            'cancel',
            $reviewer,
            'ألغي الطلب بطلب الجهة.',
        );

        $this->assertTrue($service->availableActions($transaction, $reviewer)->isEmpty());

        try {
            $service->transition($transaction, 'approve', $reviewer);
            $this->fail('A cancelled transaction must not re-enter the workflow.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('لا يمكن تنفيذ إجراء على معاملة ملغاة أو مؤرشفة.', $exception->getMessage());
        }

        $this->assertDatabaseCount('transaction_stage_logs', 1);
        $this->assertDatabaseCount('transaction_status_history', 1);
    }

    public function test_exception_rules_are_seeded_for_corrections_and_every_open_stage_can_be_cancelled(): void
    {
        $this->seed(DatabaseSeeder::class);

        $correctiveRules = WorkflowTransition::query()
            ->whereIn('action', ['return_missing_docs', 'reject_review', 'request_edit'])
            ->with(['fromStage', 'toStage'])
            ->orderBy('from_stage_id')
            ->get();

        $this->assertCount(3, $correctiveRules);
        $this->assertSame([2, 3, 4], $correctiveRules->pluck('fromStage.order_no')->all());
        $this->assertSame([1, 2, 3], $correctiveRules->pluck('toStage.order_no')->all());
        $this->assertTrue($correctiveRules->every('is_exception', true));
        $this->assertTrue($correctiveRules->every('requires_comment', true));

        $cancelRules = WorkflowTransition::query()
            ->where('action', 'cancel')
            ->with(['fromStage', 'toStage'])
            ->get();

        $this->assertCount(10, $cancelRules);
        $this->assertEqualsCanonicalizing(range(1, 10), $cancelRules->pluck('fromStage.order_no')->all());
        $this->assertTrue($cancelRules->every(
            fn (WorkflowTransition $rule) => $rule->fromStage->is($rule->toStage)
                && $rule->is_exception
                && $rule->requires_comment,
        ));
    }

    private function newTransaction(int $stageOrder = 1, string $statusCode = 'new'): Transaction
    {
        return Transaction::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'اختبار المسار الأساسي',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'transaction_type_id' => TransactionType::where('code', 'PROM')->value('id'),
            'status_id' => TransactionStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('order_no', $stageOrder)->value('id'),
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
