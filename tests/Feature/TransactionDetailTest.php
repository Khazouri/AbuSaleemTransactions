<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionStageLog;
use App\Models\TransactionStatus;
use App\Models\TransactionType;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_role_appropriate_actor_can_read_the_detail_and_advance_it(): void
    {
        $this->seed(DatabaseSeeder::class);
        $transaction = $this->newTransaction();
        $reviewer = $this->userWithRole('R02');

        Attachment::create([
            'transaction_id' => $transaction->id,
            'disk' => 'local',
            'path' => "attachments/{$transaction->id}/support.pdf",
            'original_name' => 'support.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);
        TransactionStageLog::create([
            'transaction_id' => $transaction->id,
            'to_stage_id' => $transaction->current_stage_id,
            'action' => 'intake',
            'acted_by_user_id' => $reviewer->id,
            'acted_at' => now(),
        ]);

        $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/transactions/{$transaction->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $transaction->id)
            ->assertJsonPath('data.attachments.0.original_name', 'support.pdf')
            ->assertJsonPath('data.timeline.0.action', 'intake')
            ->assertJsonPath('data.available_actions.0', 'forward');

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/transition", [
                'action' => 'forward',
                'comment' => 'تمت الإحالة للمراجعة.',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_stage.order_no', 2)
            ->assertJsonPath('data.status.code', 'in_review')
            ->assertJsonPath('data.available_actions.0', 'approve');

        $this->assertDatabaseHas('transaction_stage_logs', [
            'transaction_id' => $transaction->id,
            'action' => 'forward',
            'acted_by_user_id' => $reviewer->id,
        ]);
    }

    public function test_an_actor_without_the_configured_role_cannot_transition_from_the_detail_endpoint(): void
    {
        $this->seed(DatabaseSeeder::class);
        $transaction = $this->newTransaction();
        $employee = $this->userWithRole('R01');

        $this->actingAs($employee, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/transition", ['action' => 'forward'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');

        $transaction->refresh();
        $this->assertSame(1, $transaction->currentStage->order_no);
        $this->assertDatabaseCount('transaction_stage_logs', 0);
    }

    private function newTransaction(): Transaction
    {
        return Transaction::create([
            'reference_number' => now()->format('Y').'-ADM-800001',
            'title' => 'معاملة تفصيلية',
            'description' => 'تفاصيل المعاملة لاختبار شاشة العمل.',
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
