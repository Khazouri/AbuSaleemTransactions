<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use App\Services\CommitteeStatusService;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 102 — the handover into the committee is the مقرر's own approve.
 *
 * Stages 86 and 96 argued over who clicks the two `forward` hops through
 * observations and forward_to_committee (R09, then R02 again). The user's own
 * process removes the question: the مقرر's approve at requirements_check puts
 * the file straight on the committee's pending list, and no rule reaches or
 * leaves stages 6–8 any more. This file pins that, and — as it did for Stages
 * 86 and 96 — that a re-seed of an older database removes the rows it replaced.
 */
class CommitteeHandoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_rapporteurs_approve_lands_the_file_on_the_pending_list_and_the_secretary_cannot(): void
    {
        $this->seed(DatabaseSeeder::class);

        $service = app(WorkflowService::class);

        try {
            $service->transition($this->newRequest('requirements_check', 'in_review'), 'approve', $this->userWithRole('R09'));
            $this->fail('R09 holds no row at requirements_check.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        $moved = $service->transition($this->newRequest('requirements_check', 'in_review'), 'approve', $this->userWithRole('R02'));

        $this->assertSame('receive_from_committee', $moved->currentStage->code);
        $this->assertSame('registered', $moved->status->code);
        $this->assertNotNull($moved->reference_number);
        $this->assertTrue(app(CommitteeStatusService::class)->candidatesQuery()->whereKey($moved->id)->exists());
    }

    public function test_no_rule_reaches_or_leaves_the_three_retired_stages(): void
    {
        $this->seed(DatabaseSeeder::class);

        $retired = WorkflowStage::query()
            ->whereIn('code', ['reviewer_review', 'observations', 'forward_to_committee'])
            ->pluck('id');

        $this->assertCount(3, $retired, 'the stage rows stay — historical stage logs point at them');
        $this->assertSame(0, WorkflowTransition::query()
            ->where(fn ($query) => $query->whereIn('from_stage_id', $retired)->orWhereIn('to_stage_id', $retired))
            ->count());
    }

    /**
     * A fresh database never holds the old rows, so seeding twice would prove
     * nothing. Put back one normal and one exception row the way a
     * pre-Stage-102 install has them — exception rows survive the generic
     * delete at the top of WorkflowTransitionSeeder::run(), which is the whole
     * reason the targeted cleanup exists.
     */
    public function test_re_seeding_a_pre_stage_102_database_removes_the_retired_rows(): void
    {
        $this->seed(DatabaseSeeder::class);

        $stage = fn (string $code) => WorkflowStage::where('code', $code)->value('id');
        $r02 = Role::where('code', 'R02')->value('id');

        WorkflowTransition::create([
            'from_stage_id' => $stage('observations'),
            'to_stage_id' => $stage('forward_to_committee'),
            'action' => 'forward',
            'required_role_id' => $r02,
            'set_status_id' => RequestStatus::where('code', 'ready')->value('id'),
            'is_exception' => false,
            'requires_comment' => false,
            'order_no' => 7,
        ]);
        WorkflowTransition::create([
            'from_stage_id' => $stage('forward_to_committee'),
            'to_stage_id' => $stage('forward_to_committee'),
            'action' => 'cancel',
            'required_role_id' => $r02,
            'set_status_id' => RequestStatus::where('code', 'cancelled')->value('id'),
            'is_exception' => true,
            'requires_comment' => true,
            'order_no' => 99,
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, WorkflowTransition::query()
            ->whereIn('from_stage_id', [$stage('reviewer_review'), $stage('observations'), $stage('forward_to_committee')])
            ->count());
    }

    private function newRequest(string $stageCode, string $statusCode): Request
    {
        return Request::create([
            'title' => 'اختبار تسليم الملف إلى اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
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
