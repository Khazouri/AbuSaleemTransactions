<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\ScreenRolePermission;
use App\Models\Transaction;
use App\Models\TransactionStatus;
use App\Models\TransactionType;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviewer_queue_only_lists_its_checkpoint_and_approval_is_recorded(): void
    {
        $this->seed(DatabaseSeeder::class);

        $reviewer = $this->userWithRole('R02');
        $pending = $this->transactionAt(2, 'in_review');
        $this->transactionAt(8, 'decided');

        $this->actingAs($reviewer, 'sanctum')
            ->getJson('/api/approvals/reviewer')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.decision_grade', 10)
            ->assertJsonPath('data.0.requires_ministry_approval', true);

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/approvals/reviewer/{$pending->id}", [
                'comment' => 'تمت مراجعة المستندات واعتمادها.',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_stage.order_no', 3);

        $this->assertDatabaseHas('approvals', [
            'transaction_id' => $pending->id,
            'level' => 1,
            'role_id' => Role::where('code', 'R02')->value('id'),
            'approved_by_user_id' => $reviewer->id,
            'action' => 'approve',
            'comment' => 'تمت مراجعة المستندات واعتمادها.',
        ]);

        $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/transactions/{$pending->id}")
            ->assertOk()
            ->assertJsonPath('data.approvals.0.level', 1)
            ->assertJsonPath('data.approvals.0.role.code', 'R02')
            ->assertJsonPath('data.approvals.0.approved_by.id', $reviewer->id);
    }

    public function test_queue_permission_and_checkpoint_both_prevent_cross_level_approval(): void
    {
        $this->seed(DatabaseSeeder::class);

        $reviewer = $this->userWithRole('R02');
        $adminPending = $this->transactionAt(8, 'decided');

        $this->actingAs($reviewer, 'sanctum')
            ->getJson('/api/approvals/admin-manager')
            ->assertForbidden();

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/approvals/reviewer/{$adminPending->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaction');

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(8, $adminPending->refresh()->currentStage->order_no);
    }

    public function test_detail_transition_cannot_bypass_a_revoked_approval_screen_permission(): void
    {
        $this->seed(DatabaseSeeder::class);

        $reviewer = $this->userWithRole('R02');
        $pending = $this->transactionAt(2, 'in_review');
        ScreenRolePermission::query()
            ->where('role_id', Role::where('code', 'R02')->value('id'))
            ->whereHas('screen', fn ($query) => $query->where('code', 'reviewer_approval'))
            ->update(['can_approve' => false]);

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/transactions/{$pending->id}/transition", ['action' => 'approve'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(2, $pending->refresh()->currentStage->order_no);
    }

    private function transactionAt(int $stageOrder, string $statusCode): Transaction
    {
        return Transaction::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'معاملة في سلسلة الاعتماد',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'transaction_type_id' => TransactionType::where('code', 'PROM')->value('id'),
            'status_id' => TransactionStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('order_no', $stageOrder)->value('id'),
            'submitted_at' => now(),
            'decision_grade' => 10,
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
