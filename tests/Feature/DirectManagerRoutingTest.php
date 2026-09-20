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
 * — and the manager-gated actor check, 3-way routing branch, and the
 * status-gated registration convergence that makes routing enforceable
 * rather than decorative.
 */
class DirectManagerRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_assigned_manager_may_delegate_and_r08_holds_no_override(): void
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

        // The assigned manager may act.
        $requestRecord = $service->transition($requestRecord->refresh(), 'forward', $manager);
        $this->assertSame('administrative_routing', $requestRecord->currentStage->code);

        // An employee with no manager at all cannot have their request
        // delegated by anyone — R08 included. This is the rule, not a gap:
        // delegation is the submitter's own manager's decision.
        $unmanagedEmployee = $this->userWithRole('R01');
        $orphanRequest = $this->newRequest('direct_manager_review', 'in_review', $unmanagedEmployee->id);
        try {
            $service->transition($orphanRequest, 'forward', $admin);
            $this->fail('R08 must not be able to delegate a request whose creator has no manager.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        // The preview must agree with that refusal rather than offering a
        // button the transition endpoint would reject — the load-bearing
        // property actorMayUse() exists to keep true for both call sites.
        $this->assertSame(
            [],
            $service->availableActions($orphanRequest->refresh(), $admin)->all(),
        );
        $this->assertSame('direct_manager_review', $orphanRequest->refresh()->currentStage->code);
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

        // ...and neither can R08: a dead manager link is not an admin
        // override, so the request simply cannot move. Cancelling is refused
        // on the same grounds, which is what makes this a genuine stall —
        // recorded here deliberately so a later "usability" change cannot
        // reintroduce the override without this test failing.
        foreach (['forward', 'cancel'] as $action) {
            try {
                $service->transition($requestRecord->refresh(), $action, $admin, 'محاولة إدارية.');
                $this->fail("R08 must not be able to {$action} past a dead manager link.");
            } catch (WorkflowTransitionException $exception) {
                $this->assertSame('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.', $exception->getMessage());
            }
        }

        $this->assertSame('direct_manager_review', $requestRecord->refresh()->currentStage->code);
    }

    public function test_each_of_the_three_routes_lands_at_receive_and_register_with_its_own_status(): void
    {
        $this->seed(DatabaseSeeder::class);

        $manager = User::factory()->create(['is_active' => true]);
        $service = app(WorkflowService::class);

        foreach ([
            ['route_to_hr', 'routed_to_hr'],
            ['route_to_diwan', 'routed_to_diwan'],
            ['route_to_committee_secretary', 'routed_to_committee_secretary'],
        ] as [$action, $statusCode]) {
            $employee = $this->userWithRole('R01');
            $employee->manager_id = $manager->id;
            $employee->save();

            $requestRecord = $this->newRequest('administrative_routing', 'in_review', $employee->id);
            $moved = $service->transition($requestRecord, $action, $manager);

            $this->assertSame('receive_and_register', $moved->currentStage->code);
            $this->assertSame($statusCode, $moved->status->code);
        }
    }

    public function test_each_register_row_requires_both_its_role_and_its_matching_routed_status(): void
    {
        $this->seed(DatabaseSeeder::class);

        $service = app(WorkflowService::class);
        $registrar = $this->userWithRole('R10'); // Diwan deputy
        $requestRecord = $this->newRequest('receive_and_register', 'routed_to_hr'); // routed to HR, not Diwan

        // R10 holds the right ROLE for a Diwan-routed file, but this file was
        // routed to HR — the status gate must refuse it. This is the
        // assertion that proves routing is enforced, not merely advisory.
        try {
            $service->transition($requestRecord, 'register', $registrar);
            $this->fail('R10 must not be able to register an HR-routed file.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        // The matching registrar (R12/HR — Stage 87 replaced R05 here) succeeds
        // on the same file.
        $hrRegistrar = $this->userWithRole('R12');
        $moved = $service->transition($requestRecord->refresh(), 'register', $hrRegistrar);
        $this->assertSame('requirements_check', $moved->currentStage->code);
        // Art. 38's code 04 (تحت فحص الاكتمال), not 06: accepting the file is
        // not the قيد. المقرر grants that on the approve hop — see
        // UnifiedNumberingTest, which pins that no number is minted here.
        $this->assertSame('in_review', $moved->status->code);

        // And the reverse pairing (R12 attempting a Diwan-routed file) is
        // equally refused, confirming this isn't a one-way accident.
        $diwanRequest = $this->newRequest('receive_and_register', 'routed_to_diwan');
        try {
            $service->transition($diwanRequest, 'register', $hrRegistrar);
            $this->fail('R12 must not be able to register a Diwan-routed file.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        // Stage 87 — R05 is now refused the very action it used to hold: the
        // routing destination the poster names as HR is R12's alone, not
        // R05's, once the reassignment is real rather than additive. A fresh
        // request, since $requestRecord has already moved past this stage.
        $anotherHrRequest = $this->newRequest('receive_and_register', 'routed_to_hr');
        $formerRegistrar = $this->userWithRole('R05');
        try {
            $service->transition($anotherHrRequest, 'register', $formerRegistrar);
            $this->fail('R05 must no longer be able to register an HR-routed file.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.', $exception->getMessage());
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

        // Stage 87 — R12, not R05, is the third legitimate receiving role now.
        foreach (['R12', 'R09', 'R10'] as $registrarRole) {
            $registrar = $this->userWithRole($registrarRole);
            $requestRecord = $this->newRequest('receive_and_register', 'routed_to_hr');
            $moved = $service->transition($requestRecord, 'cancel', $registrar, 'ألغيت.');
            $this->assertSame('receive_and_register', $moved->currentStage->code);
            $this->assertSame('cancelled', $moved->status->code);
        }
    }

    public function test_the_suggested_administrative_route_is_advisory_only_all_three_routes_stay_available(): void
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

        // The suggestion is advisory, not a gate — every one of the 3
        // manual routes must still be selectable, matching Stage 56's own
        // "additive to the existing 3 paths, not a replacement" scope.
        $actions = $response->json('data.available_actions');
        $this->assertContains('route_to_hr', $actions);
        $this->assertContains('route_to_diwan', $actions);
        $this->assertContains('route_to_committee_secretary', $actions);

        // Picking the non-suggested route still works end to end.
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
     * All three registrars hold a `register` row at `receive_and_register`,
     * but `notes_attachments.add` listed only R12 — so R09 and R10 could
     * accept a file and then attach nothing to it. Walked over real HTTP
     * because the gap was in the screen-permission middleware, not in any
     * service: a direct call would have passed the whole time.
     */
    public function test_every_receiving_body_can_record_an_ifada_on_a_file_it_accepted(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');

        // Each registrar against the routing status its own `register` row is
        // gated on — the realistic case, and the one visibility resolves.
        $registrars = [
            'R12' => 'routed_to_hr',
            'R10' => 'routed_to_diwan',
            'R09' => 'routed_to_committee_secretary',
        ];

        foreach ($registrars as $roleCode => $statusCode) {
            $actor = $this->userWithRole($roleCode);
            $employee = $this->userWithRole('R01');

            // Not the creator — a receiving body never is.
            $requestRecord = $this->newRequest('receive_and_register', $statusCode, $employee->id);

            $this->actingAs($actor, 'sanctum')
                ->post("/api/requests/{$requestRecord->id}/attachments", [
                    'file' => UploadedFile::fake()->create('ifada.pdf', 40, 'application/pdf'),
                    'label' => 'إفادة الجهة المعنية',
                    'required_document_key' => 'other',
                    'file_section' => 'supporting_documents',
                ], ['Accept' => 'application/json'])
                ->assertCreated();

            $this->actingAs($actor, 'sanctum')
                ->postJson("/api/requests/{$requestRecord->id}/notes", [
                    'body' => 'استكملت الجهة ما يقع ضمن اختصاصها من بيانات.',
                ])
                ->assertCreated();
        }

        // The grant is still bounded — a role that holds no `register` row
        // here gets nothing, so this widened two seats rather than the screen.
        $outsider = $this->userWithRole('R07');
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
