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

    public function test_detail_exposes_exception_metadata_and_endpoint_preserves_the_required_reason(): void
    {
        $this->seed(DatabaseSeeder::class);
        $transaction = $this->newTransaction(2, 'in_review');
        $reviewer = $this->userWithRole('R02');

        $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/transactions/{$transaction->id}")
            ->assertOk()
            ->assertJsonFragment([
                'action' => 'return_missing_docs',
                'is_exception' => true,
                'requires_comment' => true,
            ])
            ->assertJsonFragment([
                'action' => 'cancel',
                'is_exception' => true,
                'requires_comment' => true,
            ]);

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/transition", [
                'action' => 'return_missing_docs',
                'comment' => 'صورة المستند المطلوبة غير مرفقة.',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_stage.order_no', 1)
            ->assertJsonPath('data.status.code', 'incomplete')
            ->assertJsonPath('data.timeline.0.action', 'return_missing_docs')
            ->assertJsonPath('data.timeline.0.comment', 'صورة المستند المطلوبة غير مرفقة.');
    }

    public function test_exception_endpoint_rejects_a_blank_reason(): void
    {
        $this->seed(DatabaseSeeder::class);
        $transaction = $this->newTransaction(2, 'in_review');
        $reviewer = $this->userWithRole('R02');

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/transition", [
                'action' => 'return_missing_docs',
                'comment' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');

        $transaction->refresh();
        $this->assertSame(2, $transaction->currentStage->order_no);
        $this->assertDatabaseCount('transaction_stage_logs', 0);
    }

    private function newTransaction(int $stageOrder = 1, string $statusCode = 'new'): Transaction
    {
        return Transaction::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'معاملة تفصيلية',
            'description' => 'تفاصيل المعاملة لاختبار شاشة العمل.',
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
