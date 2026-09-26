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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Diagram-alignment redesign (see AGENT_NOTES.md): the three new front-half
 * stages — direct_manager_review, administrative_routing, receive_and_register
 * — and the manager-gated actor check, the routing branch, and the
 * status-gated registration convergence that makes routing enforceable
 * rather than decorative.
 *
 * Stage 96 — the branch is ONE route, not three. [D]'s الملحق السادس has no
 * column for R10 (وكيل الديوان) or R09 (أمين سر اللجنة), and names الموارد
 * البشرية / شؤون الموظفين as the receiving party, which is R12. The status
 * gate survives the collapse and still earns its place: it is now the check
 * that a file was routed at all before anyone may register it.
 */
class DirectManagerRoutingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 2026-09-26 — R08 unsticks a manager-less file, and ONLY that: while a
     * live manager exists the step is theirs alone, admin included. Inverted
     * from the earlier "R08 holds no override" assertion by user decision.
     */
    public function test_only_the_assigned_manager_may_delegate_and_r08_only_unsticks(): void
    {
        $this->seed(DatabaseSeeder::class);

        $manager = User::factory()->create(['is_active' => true]);
        $employee = $this->userWithRole('R01');
        $employee->manager_id = $manager->id;
        $employee->save();

        $stranger = User::factory()->create(['is_active' => true]);
        $admin = $this->userWithRole('R08');
        $service = app(WorkflowService::class);

        // A non-manager (not even a random role holder) is refused.
        $requestRecord = $this->newRequest('direct_manager_review', 'in_review', $employee->id);
        try {
            $service->transition($requestRecord, 'forward', $stranger);
            $this->fail('An unrelated user must not be able to act as the manager.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        // A live manager owns the step: R08 is refused while one exists.
        $this->assertFalse($service->availableActions($requestRecord->refresh(), $admin)->contains('forward'));
        try {
            $service->transition($requestRecord->refresh(), 'forward', $admin);
            $this->fail('R08 must not act over a live manager.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        // The assigned manager may act.
        $requestRecord = $service->transition($requestRecord->refresh(), 'forward', $manager);
        $this->assertSame('administrative_routing', $requestRecord->currentStage->code);

        // An employee with no manager at all: R08 may unstick it, and the
        // preview offers exactly what the endpoint accepts.
        $unmanagedEmployee = $this->userWithRole('R01');
        $orphanRequest = $this->newRequest('direct_manager_review', 'in_review', $unmanagedEmployee->id);
        $this->assertTrue($service->availableActions($orphanRequest, $admin)->contains('forward'));
        $this->assertFalse($service->availableActions($orphanRequest, $stranger)->contains('forward'));

        $moved = $service->transition($orphanRequest, 'forward', $admin);
        $this->assertSame('administrative_routing', $moved->currentStage->code);

        // ...but not its own file: the fallback is not a way round review.
        $orphanAdmin = $this->userWithRole('R08');
        $ownRequest = $this->newRequest('direct_manager_review', 'in_review', $orphanAdmin->id);
        $this->assertFalse($service->availableActions($ownRequest, $orphanAdmin)->contains('forward'));
    }

    public function test_an_inactive_manager_leaves_the_request_undelegatable_rather_than_500ing(): void
    {
        $this->seed(DatabaseSeeder::class);

        $employee = $this->userWithRole('R01');
        $inactiveManager = User::factory()->create(['is_active' => false]);
        $employee->manager_id = $inactiveManager->id;
        $employee->save();

        $admin = $this->userWithRole('R08');
        $service = app(WorkflowService::class);
        $requestRecord = $this->newRequest('direct_manager_review', 'in_review', $employee->id);

        // The deactivated manager can no longer act at all (WorkflowService
        // refuses any inactive actor outright, before rule matching even
        // starts) — separately, actorIsCreatorsActiveManager() is what stops
        // a DIFFERENT actor from resolving this dangling manager_id as a
        // still-live manager link. The dangling link must fail closed and
        // quietly, never with a 500.
        try {
            $service->transition($requestRecord, 'forward', $inactiveManager);
            $this->fail('A deactivated manager must not be able to act.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('لا يمكن لمستخدم غير نشط تنفيذ إجراء سير العمل.', $exception->getMessage());
        }

        // A dead manager link is the stall R08 exists to release (2026-09-26):
        // forward and cancel are both offered to the admin now.
        $actions = $service->availableActions($requestRecord->refresh(), $admin);
        $this->assertTrue($actions->contains('forward'));
        $this->assertTrue($actions->contains('cancel'));

        $moved = $service->transition($requestRecord->refresh(), 'cancel', $admin, 'إلغاء إداري.');
        $this->assertSame('cancelled', $moved->status->code);
    }

    /**
     * An R08 filer matched both `submit` rows (the creator row and the R08
     * exception), which failed closed as ambiguous — the admin could never
     * re-submit its own returned request.
     */
    public function test_an_admin_filer_can_resubmit_its_own_returned_request(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = $this->userWithRole('R08');
        $service = app(WorkflowService::class);
        $requestRecord = $this->newRequest('receive_from_municipality', 'returned', $admin->id);

        $this->assertTrue($service->availableActions($requestRecord, $admin)->contains('submit'));

        $moved = $service->transition($requestRecord, 'submit', $admin);
        $this->assertSame('direct_manager_review', $moved->currentStage->code);
    }

    public function test_the_one_remaining_route_lands_at_receive_and_register_and_the_two_retired_ones_are_gone(): void
    {
        $this->seed(DatabaseSeeder::class);

        $manager = User::factory()->create(['is_active' => true]);
        $service = app(WorkflowService::class);

        $employee = $this->userWithRole('R01');
        $employee->manager_id = $manager->id;
        $employee->save();

        $moved = $service->transition(
            $this->newRequest('administrative_routing', 'in_review', $employee->id),
            'route_to_hr',
            $manager,
        );

        $this->assertSame('receive_and_register', $moved->currentStage->code);
        $this->assertSame('routed_to_hr', $moved->status->code);

        // Stage 96 — the two retired rows are exceptions, which the generic
        // delete at the top of WorkflowTransitionSeeder::run() does NOT sweep,
        // so their own targeted delete is the only thing removing them. This
        // is the assertion that would fail if that delete were dropped.
        foreach (['route_to_diwan', 'route_to_committee_secretary'] as $retired) {
            try {
                $service->transition(
                    $this->newRequest('administrative_routing', 'in_review', $employee->id),
                    $retired,
                    $manager,
                );
                $this->fail("{$retired} should no longer exist as a route.");
            } catch (WorkflowTransitionException $exception) {
                $this->assertSame(
                    'هذا الإجراء غير متاح في المرحلة الحالية للطلب.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_the_register_row_requires_both_r12_and_a_routed_status(): void
    {
        $this->seed(DatabaseSeeder::class);

        $service = app(WorkflowService::class);
        $hrRegistrar = $this->userWithRole('R12');

        $moved = $service->transition(
            $this->newRequest('receive_and_register', 'routed_to_hr'),
            'register',
            $hrRegistrar,
        );
        $this->assertSame('requirements_check', $moved->currentStage->code);
        // Art. 38's code 04 (تحت فحص الاكتمال), not 06: accepting the file is
        // not the قيد. المقرر grants that on the approve hop — see
        // UnifiedNumberingTest, which pins that no number is minted here.
        $this->assertSame('in_review', $moved->status->code);

        // The status gate survives the collapse to one route, and this is what
        // it now enforces: a file that was never routed cannot be registered.
        try {
            $service->transition(
                $this->newRequest('receive_and_register', 'in_review'),
                'register',
                $hrRegistrar,
            );
            $this->fail('An unrouted file must not be registrable.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        // Stage 87 refused R05 here; Stage 96 refuses R10 and R09 the same
        // way, for the same reason — the receiving party [D] Appendix 6 names
        // is الموارد البشرية, and none of the three is it.
        foreach (['R05', 'R10', 'R09'] as $formerRegistrarRole) {
            try {
                $service->transition(
                    $this->newRequest('receive_and_register', 'routed_to_hr'),
                    'register',
                    $this->userWithRole($formerRegistrarRole),
                );
                $this->fail("{$formerRegistrarRole} must no longer be able to register.");
            } catch (WorkflowTransitionException $exception) {
                $this->assertSame('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.', $exception->getMessage());
            }
        }
    }

    public function test_return_to_employee_sends_it_back_to_stage_one_and_requires_a_comment(): void
    {
        $this->seed(DatabaseSeeder::class);

        $manager = User::factory()->create(['is_active' => true]);
        $employee = $this->userWithRole('R01');
        $employee->manager_id = $manager->id;
        $employee->save();

        $service = app(WorkflowService::class);
        $requestRecord = $this->newRequest('direct_manager_review', 'in_review', $employee->id);

        try {
            $service->transition($requestRecord, 'return_to_employee', $manager);
            $this->fail('return_to_employee should require a comment.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('يجب إدخال سبب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        $moved = $service->transition($requestRecord->refresh(), 'return_to_employee', $manager, 'بيانات ناقصة.');
        $this->assertSame('receive_from_municipality', $moved->currentStage->code);
        $this->assertSame('returned', $moved->status->code);
    }

    public function test_cancel_works_at_all_three_new_stages(): void
    {
        $this->seed(DatabaseSeeder::class);

        $manager = User::factory()->create(['is_active' => true]);
        $service = app(WorkflowService::class);

        foreach (['direct_manager_review', 'administrative_routing'] as $managerGatedStage) {
            $employee = $this->userWithRole('R01');
            $employee->manager_id = $manager->id;
            $employee->save();

            $requestRecord = $this->newRequest($managerGatedStage, 'in_review', $employee->id);
            $moved = $service->transition($requestRecord, 'cancel', $manager, 'ألغيت بناء على طلب الموظف.');
            $this->assertSame($managerGatedStage, $moved->currentStage->code);
            $this->assertSame('cancelled', $moved->status->code);
        }

        // Stage 96 — R12 is the only receiving role, so it is the only one
        // that can stop a file sitting with it.
        $moved = $service->transition(
            $this->newRequest('receive_and_register', 'routed_to_hr'),
            'cancel',
            $this->userWithRole('R12'),
            'ألغيت.',
        );
        $this->assertSame('receive_and_register', $moved->currentStage->code);
        $this->assertSame('cancelled', $moved->status->code);

        // The superseded R09/R10 cancel rows are exceptions, so only their own
        // targeted delete removes them — this fails if that delete is dropped.
        foreach (['R09', 'R10'] as $retiredRegistrar) {
            try {
                $service->transition(
                    $this->newRequest('receive_and_register', 'routed_to_hr'),
                    'cancel',
                    $this->userWithRole($retiredRegistrar),
                    'ألغيت.',
                );
                $this->fail("{$retiredRegistrar} must no longer hold cancel here.");
            } catch (WorkflowTransitionException $exception) {
                $this->assertSame('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.', $exception->getMessage());
            }
        }
    }

    public function test_the_stage_56_route_suggestion_is_inert_now_that_only_one_route_exists(): void
    {
        $this->seed(DatabaseSeeder::class);

        // request_details,view is granted per-role ('*' means every seeded
        // role, not literally anyone) — the manager needs a role of some
        // kind to pass the HTTP screen-permission gate below.
        $manager = $this->userWithRole('R02');
        $employee = $this->userWithRole('R01');
        $employee->manager_id = $manager->id;
        $employee->save();

        // newRequest() always seeds a PROM request, whose Stage 56 default
        // suggestion is committee_secretary (see RequestTypeSeeder).
        $requestRecord = $this->newRequest('administrative_routing', 'in_review', $employee->id);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();

        $response->assertJsonPath('data.request_type.default_administrative_route', 'committee_secretary');

        // Stage 96 — the column and its three-value vocabulary are left
        // untouched (narrowing them is a decision about Stage 56's own
        // mechanism, which Stage 96 does not make), but with one route the
        // badge has nothing to attach to: the SPA renders it only where
        // item.action === suggestedRoutingAction. This asserts the consequence
        // rather than hiding it — ten of the twelve seeded types now suggest a
        // destination that no longer exists.
        $actions = $response->json('data.available_actions');
        $this->assertSame(['route_to_hr'], array_values(array_intersect($actions, [
            'route_to_hr',
            'route_to_diwan',
            'route_to_committee_secretary',
        ])));
        $this->assertNotContains('route_to_committee_secretary', $actions);

        // The one route still works end to end.
        $service = app(WorkflowService::class);
        $moved = $service->transition($requestRecord->refresh(), 'route_to_hr', $manager);
        $this->assertSame('receive_and_register', $moved->currentStage->code);
        $this->assertSame('routed_to_hr', $moved->status->code);
    }

    public function test_available_transitions_and_transition_agree_for_a_manager_gated_row(): void
    {
        $this->seed(DatabaseSeeder::class);

        $manager = User::factory()->create(['is_active' => true]);
        $employee = $this->userWithRole('R01');
        $employee->manager_id = $manager->id;
        $employee->save();

        $stranger = User::factory()->create(['is_active' => true]);
        $service = app(WorkflowService::class);
        $requestRecord = $this->newRequest('direct_manager_review', 'in_review', $employee->id);

        // The UI must not offer a button execution would refuse...
        $this->assertFalse($service->availableActions($requestRecord, $stranger)->contains('forward'));

        // ...and must not hide one execution would accept.
        $this->assertTrue($service->availableActions($requestRecord, $manager)->contains('forward'));
    }

    /**
     * 2026-09-20 — [E] stage 03: the receiving body «تستكمل ما يقع ضمن
     * اختصاصها من بيانات وإفادات» before referring to المقرر.
     *
     * Stage 96 — the grant follows the bound rather than outliving it: R09
     * and R10 were added to `notes_attachments.add` because all three held a
     * `register` row here, and they were removed again with the row. R12 is
     * the receiving body [D] Appendix 6 names. Walked over real HTTP because
     * the gate is screen-permission middleware, not a service: a direct call
     * would pass either way.
     */
    public function test_the_receiving_body_can_record_an_ifada_on_a_file_it_accepted(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');

        $actor = $this->userWithRole('R12');
        $employee = $this->userWithRole('R01');

        // Not the creator — a receiving body never is.
        $requestRecord = $this->newRequest('receive_and_register', 'routed_to_hr', $employee->id);

        // Only the filer attaches documents; HR's one exception is Stage 98's
        // الملف الوظيفي (Request::attachmentRight()). A free-standing إفادة is
        // therefore refused as an attachment and recorded as a note instead,
        // while a service-file row is accepted.
        $this->actingAs($actor, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('ifada.pdf', 40, 'application/pdf'),
                'label' => 'إفادة الجهة المعنية',
                'required_document_key' => 'other',
                'file_section' => 'supporting_documents',
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        $serviceFileKey = collect($requestRecord->requestType->documentOptions())
            ->filter(fn (array $document) => ($document['section'] ?? null) === 'service_file')
            ->keys()
            ->first();

        $this->actingAs($actor, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('service-record.pdf', 40, 'application/pdf'),
                'required_document_key' => $serviceFileKey,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.file_section', 'service_file');

        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/notes", [
                'body' => 'استكملت الجهة ما يقع ضمن اختصاصها من بيانات.',
            ])
            ->assertCreated();

        // The grant is still bounded — a role that holds no `register` row
        // here gets nothing. Stage 100 gave R06/R07 `notes_attachments,add` (Appendix 6 row 13's supervision), so R10 — a retained login with no duty since Stage 96 — is the role that holds no such grant.
        $outsider = $this->userWithRole('R10');
        $requestRecord = $this->newRequest('receive_and_register', 'routed_to_hr', $this->userWithRole('R01')->id);

        $this->actingAs($outsider, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('ifada.pdf', 40, 'application/pdf'),
                'required_document_key' => 'other',
                'file_section' => 'supporting_documents',
            ], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    private function newRequest(
        string $stageCode = 'receive_from_municipality',
        string $statusCode = 'new',
        ?int $createdByUserId = null,
    ): Request {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'اختبار المسار الإداري الجديد',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'submitted_at' => now(),
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
