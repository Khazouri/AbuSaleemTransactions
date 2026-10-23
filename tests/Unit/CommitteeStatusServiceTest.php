<?php

namespace Tests\Unit;

use App\Exceptions\CommitteeStatusTransitionException;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\CommitteeStatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommitteeStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_legal_review_round_trip_never_moves_the_stage_or_logs_a_stage_move(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        $requestRecord = $this->committeeRequest('registered');
        $service = app(CommitteeStatusService::class);
        $committeeStageId = $requestRecord->current_stage_id;

        foreach ([['send_to_legal_review', 'under_legal_review'], ['pass_legal_review', 'ready']] as [$action, $expectedStatus]) {
            $requestRecord = $service->move($requestRecord, $action, $actor);

            $this->assertSame($committeeStageId, $requestRecord->current_stage_id);
            $this->assertSame($expectedStatus, $requestRecord->status->code);
        }

        $this->assertDatabaseCount('request_stage_logs', 0);
        $this->assertCount(2, $requestRecord->statusHistory);
    }

    public function test_require_completion_needs_a_comment(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        $requestRecord = $this->committeeRequest('ready');
        $service = app(CommitteeStatusService::class);

        try {
            $service->move($requestRecord, 'require_completion', $actor);
            $this->fail('require_completion should demand a reason.');
        } catch (CommitteeStatusTransitionException $exception) {
            $this->assertSame('يجب إدخال سبب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        $requestRecord = $service->move($requestRecord, 'require_completion', $actor, 'يلزم استكمال مستند مالي.');
        $this->assertSame('completion_required', $requestRecord->status->code);
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $requestRecord->id,
            'reason' => 'يلزم استكمال مستند مالي.',
        ]);
    }

    public function test_move_rejects_an_action_not_allowed_from_the_current_status_without_mutating_state(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        $requestRecord = $this->committeeRequest('ready');
        $service = app(CommitteeStatusService::class);

        try {
            // pass_legal_review is only reachable from under_legal_review.
            $service->move($requestRecord, 'pass_legal_review', $actor);
            $this->fail('pass_legal_review should be rejected from ready.');
        } catch (CommitteeStatusTransitionException $exception) {
            $this->assertSame('هذا الإجراء غير متاح في الحالة الراهنة للطلب.', $exception->getMessage());
        }

        $requestRecord->refresh();
        $this->assertSame('ready', $requestRecord->status->code);
        $this->assertDatabaseCount('request_status_history', 0);
    }

    public function test_move_rejects_a_request_not_at_the_committee_stage(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        $requestRecord = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'اختبار حالة اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_review')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'requirements_check')->value('id'),
            'submitted_at' => now(),
        ]);

        try {
            app(CommitteeStatusService::class)->move($requestRecord, 'send_to_legal_review', $actor);
            $this->fail('A committee move should be rejected off the committee stage.');
        } catch (CommitteeStatusTransitionException $exception) {
            $this->assertSame(
                'لا يمكن تنفيذ إجراءات اللجنة إلا على طلب قيد الاستلام من اللجنة.',
                $exception->getMessage(),
            );
        }

        $this->assertSame('requirements_check', $requestRecord->refresh()->currentStage->code);
    }

    public function test_move_rejects_an_inactive_actor(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        $actor->update(['is_active' => false]);
        $requestRecord = $this->committeeRequest('in_meeting');

        try {
            app(CommitteeStatusService::class)->move($requestRecord, 'send_to_legal_review', $actor);
            $this->fail('An inactive actor must not move a committee status.');
        } catch (CommitteeStatusTransitionException $exception) {
            $this->assertSame('لا يمكن لمستخدم غير نشط تنفيذ إجراء حالة اللجنة.', $exception->getMessage());
        }
    }

    public function test_move_rejects_a_cancelled_request(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        $requestRecord = $this->committeeRequest('cancelled');

        try {
            app(CommitteeStatusService::class)->move($requestRecord, 'send_to_legal_review', $actor);
            $this->fail('A cancelled request must not accept a committee status move.');
        } catch (CommitteeStatusTransitionException $exception) {
            $this->assertSame('لا يمكن تنفيذ إجراء على طلب ملغى أو مؤرشف.', $exception->getMessage());
        }
    }

    private function committeeRequest(string $statusCode): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'اختبار حالة اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
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
