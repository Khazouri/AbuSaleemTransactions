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
            ->with(['fromStage', 'toStage', 'requiredRole'])
            ->orderBy('order_no')
            ->get();

        $this->assertCount(10, $rules);
        $this->assertSame(range(1, 10), $rules->pluck('fromStage.order_no')->all());
        $this->assertSame(range(2, 11), $rules->pluck('toStage.order_no')->all());
        $this->assertFalse($rules->contains('is_exception', true));
        $this->assertFalse($rules->contains('requires_comment', true));
    }

    private function newTransaction(): Transaction
    {
        return Transaction::create([
            'reference_number' => now()->format('Y').'-ADM-900001',
            'title' => 'اختبار المسار الأساسي',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'transaction_type_id' => TransactionType::where('code', 'PROM')->value('id'),
            'status_id' => TransactionStatus::where('code', 'new')->value('id'),
            'current_stage_id' => WorkflowStage::where('order_no', 1)->value('id'),
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
