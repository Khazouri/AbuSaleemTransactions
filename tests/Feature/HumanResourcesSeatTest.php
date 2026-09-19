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
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 87 — [F] names إدارة الموارد البشرية twice: the route_to_hr
 * registration destination (covered by DirectManagerRoutingTest, since it's
 * the same registration-convergence mechanism that test already exercises)
 * and co-owner of the study at `observations`. This file covers the second
 * half only — the new, bounded RequestVisibility clause that grants R12
 * (مدير إدارة الموارد البشرية) a read/contribute reach into that one stage
 * without giving it any control over it.
 */
class HumanResourcesSeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_hr_manager_can_open_a_request_sitting_at_observations_they_did_not_create(): void
    {
        $hrManager = $this->userWithRole('R12');
        $creator = $this->userWithRole('R01');
        $requestRecord = $this->requestAtStage('observations', $creator);

        $this->actingAs($hrManager)
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();
    }

    /**
     * forward_to_committee is R09's outbound stage now (Stage 86); R12 holds
     * no rule there and the observations-only clause does not cover it. An
     * UNREGISTERED file at that stage is the isolated proof that the
     * $isHrStudyCoOwner bound really is exactly one stage, not "anywhere past
     * intake" — see the next test for why a real, registered file at the same
     * stage is visible anyway, through a completely different clause.
     */
    public function test_the_hr_manager_loses_the_study_seats_own_reach_once_the_request_moves_past_observations(): void
    {
        $hrManager = $this->userWithRole('R12');
        $creator = $this->userWithRole('R01');
        $requestRecord = $this->requestAtStage('forward_to_committee', $creator, status: 'ready', registered: false);

        $this->actingAs($hrManager)
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertNotFound();
    }

    /**
     * Stage 92 gave R12 a second, unrelated reach: `meeting_outputs,approve`
     * (execute/close, [F] step 10's "who executed") puts R12 in `$isCloser`
     * alongside R02/R03, whose own bound already covers every REGISTERED
     * request — Stage 83's "not a new disclosure" reasoning, since
     * reports/registers already list this population to every role. A file
     * genuinely past intake (رقم إشاري granted) is therefore visible to R12
     * too, not because the study co-ownership widened, but because R12 is now
     * also one of the roles that may eventually execute or close a file — and
     * needs to find it first. The previous test proves the study clause
     * itself did not widen.
     */
    public function test_the_hr_managers_execution_reach_covers_a_registered_file_past_observations(): void
    {
        $hrManager = $this->userWithRole('R12');
        $creator = $this->userWithRole('R01');
        $requestRecord = $this->requestAtStage('forward_to_committee', $creator, status: 'ready');

        $this->actingAs($hrManager)
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();
    }

    public function test_an_admin_manager_still_cannot_open_a_request_at_observations(): void
    {
        // The control this test exists for: R05 (مدير إدارة الشؤون
        // الإدارية) is a different role from R12 and must not inherit this
        // reach just because it was once conflated with "HR" in this system.
        $adminManager = $this->userWithRole('R05');
        $creator = $this->userWithRole('R01');
        $requestRecord = $this->requestAtStage('observations', $creator);

        $this->actingAs($adminManager)
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertNotFound();
    }

    public function test_the_hr_manager_has_no_available_actions_at_observations(): void
    {
        $hrManager = $this->userWithRole('R12');
        $creator = $this->userWithRole('R01');
        $requestRecord = $this->requestAtStage('observations', $creator);

        $response = $this->actingAs($hrManager)
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();

        // Co-owner of the study means visibility and the ability to
        // contribute a note, never the ability to move the stage — `forward`
        // out of observations is R09's, and `request_edit`/`cancel` are
        // R02's (Stage 86's own settled rule).
        $this->assertSame([], $response->json('data.available_actions'));
    }

    public function test_the_hr_manager_can_post_a_note_while_the_request_is_at_observations(): void
    {
        $hrManager = $this->userWithRole('R12');
        $creator = $this->userWithRole('R01');
        $requestRecord = $this->requestAtStage('observations', $creator);

        $this->actingAs($hrManager)
            ->postJson("/api/requests/{$requestRecord->id}/notes", ['body' => 'ملاحظة من إدارة الموارد البشرية.'])
            ->assertCreated();
    }

    public function test_the_hr_manager_cannot_forward_or_edit_the_request_at_observations(): void
    {
        $hrManager = $this->userWithRole('R12');
        $creator = $this->userWithRole('R01');
        $service = app(WorkflowService::class);

        foreach (['forward', 'request_edit', 'cancel'] as $action) {
            $requestRecord = $this->requestAtStage('observations', $creator);

            try {
                $service->transition($requestRecord, $action, $hrManager, $action === 'forward' ? null : 'سبب.');
                $this->fail("R12 must not be able to {$action} at observations.");
            } catch (WorkflowTransitionException $exception) {
                $this->assertSame('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.', $exception->getMessage());
            }
        }
    }

    private function requestAtStage(string $stageCode, User $creator, string $status = 'in_review', bool $registered = true): Request
    {
        return Request::create([
            'reference_number' => $registered ? '2026-ADM-'.fake()->unique()->numerify('######') : null,
            'title' => 'اختبار مقعد إدارة الموارد البشرية',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $status)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $creator->id,
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
