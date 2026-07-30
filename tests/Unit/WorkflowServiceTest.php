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
            [5, 6, 'forward', 'R05', 'ready'],
            [6, 7, 'forward', 'R05', 'in_meeting'],
            [7, 8, 'approve', 'R03', 'decided'],
            [8, 9, 'approve', 'R05', 'approved'],
            [9, 10, 'approve', 'R06', 'approved'],
            [10, 11, 'approve', 'R07', 'final_approved'],
            [11, 11, 'approve', 'R07', 'archived'],
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
        $this->assertCount(11, $transaction->stageLogs);
        $this->assertCount(11, $transaction->statusHistory);
        $this->assertSame(
            [...range(2, 11), 11],
            $transaction->stageLogs()
                ->with('toStage')
                ->orderBy('id')
                ->get()
                ->pluck('toStage.order_no')
                ->all(),
        );
        $this->assertSame(range(1, 6), $transaction->approvals()->orderBy('id')->pluck('level')->all());
        $this->assertSame(
            ['R02', 'R03', 'R05', 'R06', 'R07', 'R07'],
            $transaction->approvals()->with('role')->orderBy('id')->get()->pluck('role.code')->all(),
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

        $this->assertCount(11, $rules);
        $this->assertSame(range(1, 11), $rules->pluck('fromStage.order_no')->all());
        $this->assertSame([...range(2, 11), 11], $rules->pluck('toStage.order_no')->all());
        $this->assertSame(
            ['R02', 'R02', 'R02', 'R02', 'R05', 'R05', 'R03', 'R05', 'R06', 'R07', 'R07'],
            $rules->pluck('requiredRole.code')->all(),
        );
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

        $this->assertCount(11, $cancelRules);
        $this->assertEqualsCanonicalizing(range(1, 11), $cancelRules->pluck('fromStage.order_no')->all());
        $this->assertTrue($cancelRules->every(
            fn (WorkflowTransition $rule) => $rule->fromStage->is($rule->toStage)
                && $rule->is_exception
                && $rule->requires_comment,
        ));
    }

    public function test_low_grade_transaction_skips_ministry_but_preserves_the_other_approval_levels(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actors = collect(['R02', 'R03', 'R05', 'R07'])
            ->mapWithKeys(fn (string $roleCode) => [
                $roleCode => $this->userWithRole($roleCode),
            ]);
        $service = app(WorkflowService::class);
        $transaction = $this->newTransaction(decisionGrade: 9);

        foreach ([
            ['forward', 'R02'],
            ['approve', 'R02'],
            ['forward', 'R02'],
            ['forward', 'R02'],
            ['forward', 'R05'],
            ['forward', 'R05'],
            ['approve', 'R03'],
        ] as [$action, $role]) {
            $transaction = $service->transition($transaction, $action, $actors[$role]);
        }

        $transaction = $service->transition($transaction, 'approve', $actors['R05']);
        $this->assertSame(10, $transaction->currentStage->order_no);

        $transaction = $service->transition($transaction, 'approve', $actors['R07']);
        $transaction = $service->transition($transaction, 'approve', $actors['R07']);

        $this->assertSame('archived', $transaction->status->code);
        $this->assertSame([1, 2, 3, 5, 6], $transaction->approvals()->orderBy('id')->pluck('level')->all());
        $this->assertDatabaseMissing('approvals', [
            'transaction_id' => $transaction->id,
            'level' => 4,
        ]);
    }

    public function test_actor_cannot_skip_the_admin_manager_checkpoint(): void
    {
        $this->seed(DatabaseSeeder::class);

        $transaction = $this->newTransaction(8, 'decided');
        $ministry = $this->userWithRole('R06');

        try {
            app(WorkflowService::class)->transition($transaction, 'approve', $ministry);
            $this->fail('Ministry must not be able to approve before the admin manager.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame(
                'لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(8, $transaction->refresh()->currentStage->order_no);
    }

    private function newTransaction(
        int $stageOrder = 1,
        string $statusCode = 'new',
        int $decisionGrade = 10,
    ): Transaction {
        return Transaction::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'اختبار المسار الأساسي',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'transaction_type_id' => TransactionType::where('code', 'PROM')->value('id'),
            'status_id' => TransactionStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('order_no', $stageOrder)->value('id'),
            'submitted_at' => now(),
            'decision_grade' => $decisionGrade,
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
