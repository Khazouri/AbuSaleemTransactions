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

    public function test_full_walk_through_committee_substates_never_moves_the_stage_or_logs_a_stage_move(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        // Stage 102 removed `nominate` (picking a request for a meeting is the
        // nomination), so the walk starts from the legacy nominated status.
        $requestRecord = $this->committeeRequest('nominated_for_committee');
        $service = app(CommitteeStatusService::class);
        $committeeStageId = $requestRecord->current_stage_id;

        $steps = [
            ['place_on_agenda', 'on_agenda'],
            ['start_discussion', 'under_discussion'],
            ['send_for_recommendation_approval', 'awaiting_recommendation_approval'],
        ];

        foreach ($steps as [$action, $expectedStatus]) {
            $requestRecord = $service->move($requestRecord, $action, $actor);

            $this->assertSame($committeeStageId, $requestRecord->current_stage_id);
            $this->assertSame($expectedStatus, $requestRecord->status->code);
        }

        $this->assertDatabaseCount('request_stage_logs', 0);
        $this->assertCount(3, $requestRecord->statusHistory);
    }

    public function test_require_completion_needs_a_comment_and_resume_discussion_returns_to_under_discussion(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        $requestRecord = $this->committeeRequest('under_discussion');
        $service = app(CommitteeStatusService::class);

        try {
            $service->move($requestRecord, 'require_completion', $actor);
            $this->fail('require_completion should demand a reason.');
        } catch (CommitteeStatusTransitionException $exception) {
            $this->assertSame('يجب إدخال سبب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        $requestRecord = $service->move($requestRecord, 'require_completion', $actor, 'يلزم استكمال مستند مالي.');
        $this->assertSame('completion_required', $requestRecord->status->code);

        $requestRecord = $service->move($requestRecord, 'resume_discussion', $actor);
        $this->assertSame('under_discussion', $requestRecord->status->code);

        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $requestRecord->id,
            'reason' => 'يلزم استكمال مستند مالي.',
        ]);
        $this->assertDatabaseCount('request_stage_logs', 0);
    }

    public function test_remove_from_agenda_requires_a_comment_and_returns_to_nominated(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        $requestRecord = $this->committeeRequest('on_agenda');
        $service = app(CommitteeStatusService::class);

        try {
            $service->move($requestRecord, 'remove_from_agenda', $actor);
            $this->fail('remove_from_agenda should demand a reason.');
        } catch (CommitteeStatusTransitionException $exception) {
            $this->assertSame('يجب إدخال سبب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        $requestRecord = $service->move($requestRecord, 'remove_from_agenda', $actor, 'تعارض في الجدول.');
        $this->assertSame('nominated_for_committee', $requestRecord->status->code);
    }

    public function test_move_rejects_an_action_not_allowed_from_the_current_status_without_mutating_state(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R03');
        $requestRecord = $this->committeeRequest('nominated_for_committee');
        $service = app(CommitteeStatusService::class);

        try {
            // start_discussion is only reachable from on_agenda.
            $service->move($requestRecord, 'start_discussion', $actor);
            $this->fail('start_discussion should be rejected from nominated_for_committee.');
        } catch (CommitteeStatusTransitionException $exception) {
            $this->assertSame('هذا الإجراء غير متاح في الحالة الراهنة للطلب.', $exception->getMessage());
        }

        $requestRecord->refresh();
        $this->assertSame('nominated_for_committee', $requestRecord->status->code);
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
