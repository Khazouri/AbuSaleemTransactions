<?php

namespace Tests\Unit;

use App\Exceptions\CommitteeStatusTransitionException;
use App\Models\Department;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionStatus;
use App\Models\TransactionType;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\CommitteeStatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommitteeStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_walk_through_committee_substates_never_moves_the_stage_or_logs_a_stage_move(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        $transaction = $this->committeeTransaction('in_meeting');
        $service = app(CommitteeStatusService::class);
        $committeeStageId = $transaction->current_stage_id;

        $steps = [
            ['nominate', 'nominated_for_committee'],
            ['place_on_agenda', 'on_agenda'],
            ['start_discussion', 'under_discussion'],
            ['send_for_recommendation_approval', 'awaiting_recommendation_approval'],
        ];

        foreach ($steps as [$action, $expectedStatus]) {
            $transaction = $service->move($transaction, $action, $actor);

            $this->assertSame($committeeStageId, $transaction->current_stage_id);
            $this->assertSame($expectedStatus, $transaction->status->code);
        }

        $this->assertDatabaseCount('transaction_stage_logs', 0);
        $this->assertCount(4, $transaction->statusHistory);
    }

    public function test_require_completion_needs_a_comment_and_resume_discussion_returns_to_under_discussion(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        $transaction = $this->committeeTransaction('under_discussion');
        $service = app(CommitteeStatusService::class);

        try {
            $service->move($transaction, 'require_completion', $actor);
            $this->fail('require_completion should demand a reason.');
        } catch (CommitteeStatusTransitionException $exception) {
            $this->assertSame('يجب إدخال سبب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        $transaction = $service->move($transaction, 'require_completion', $actor, 'يلزم استكمال مستند مالي.');
        $this->assertSame('completion_required', $transaction->status->code);

        $transaction = $service->move($transaction, 'resume_discussion', $actor);
        $this->assertSame('under_discussion', $transaction->status->code);

        $this->assertDatabaseHas('transaction_status_history', [
            'transaction_id' => $transaction->id,
            'reason' => 'يلزم استكمال مستند مالي.',
        ]);
        $this->assertDatabaseCount('transaction_stage_logs', 0);
    }

    public function test_remove_from_agenda_requires_a_comment_and_returns_to_nominated(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        $transaction = $this->committeeTransaction('on_agenda');
        $service = app(CommitteeStatusService::class);

        try {
            $service->move($transaction, 'remove_from_agenda', $actor);
            $this->fail('remove_from_agenda should demand a reason.');
        } catch (CommitteeStatusTransitionException $exception) {
            $this->assertSame('يجب إدخال سبب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        $transaction = $service->move($transaction, 'remove_from_agenda', $actor, 'تعارض في الجدول.');
        $this->assertSame('nominated_for_committee', $transaction->status->code);
    }

    public function test_move_rejects_an_action_not_allowed_from_the_current_status_without_mutating_state(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        $transaction = $this->committeeTransaction('nominated_for_committee');
        $service = app(CommitteeStatusService::class);

        try {
            // start_discussion is only reachable from on_agenda.
            $service->move($transaction, 'start_discussion', $actor);
            $this->fail('start_discussion should be rejected from nominated_for_committee.');
        } catch (CommitteeStatusTransitionException $exception) {
            $this->assertSame('هذا الإجراء غير متاح في الحالة الراهنة للمعاملة.', $exception->getMessage());
        }

        $transaction->refresh();
        $this->assertSame('nominated_for_committee', $transaction->status->code);
        $this->assertDatabaseCount('transaction_status_history', 0);
    }

    public function test_move_rejects_a_transaction_not_at_the_committee_stage(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        $transaction = Transaction::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'اختبار حالة اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'transaction_type_id' => TransactionType::where('code', 'PROM')->value('id'),
            'status_id' => TransactionStatus::where('code', 'in_review')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'requirements_check')->value('id'),
            'submitted_at' => now(),
        ]);

        try {
            app(CommitteeStatusService::class)->move($transaction, 'nominate', $actor);
            $this->fail('nominate should be rejected off the committee stage.');
        } catch (CommitteeStatusTransitionException $exception) {
            $this->assertSame(
                'لا يمكن تنفيذ إجراءات اللجنة إلا على معاملة قيد الاستلام من اللجنة.',
                $exception->getMessage(),
            );
        }

        $this->assertSame('requirements_check', $transaction->refresh()->currentStage->code);
    }

    public function test_move_rejects_an_inactive_actor(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        $actor->update(['is_active' => false]);
        $transaction = $this->committeeTransaction('in_meeting');

        try {
            app(CommitteeStatusService::class)->move($transaction, 'nominate', $actor);
            $this->fail('An inactive actor must not move a committee status.');
        } catch (CommitteeStatusTransitionException $exception) {
            $this->assertSame('لا يمكن لمستخدم غير نشط تنفيذ إجراء حالة اللجنة.', $exception->getMessage());
        }
    }

    public function test_move_rejects_a_cancelled_transaction(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        $transaction = $this->committeeTransaction('cancelled');

        try {
            app(CommitteeStatusService::class)->move($transaction, 'nominate', $actor);
            $this->fail('A cancelled transaction must not accept a committee status move.');
        } catch (CommitteeStatusTransitionException $exception) {
            $this->assertSame('لا يمكن تنفيذ إجراء على معاملة ملغاة أو مؤرشفة.', $exception->getMessage());
        }
    }

    private function committeeTransaction(string $statusCode): Transaction
    {
        return Transaction::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'اختبار حالة اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'transaction_type_id' => TransactionType::where('code', 'PROM')->value('id'),
            'status_id' => TransactionStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
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
