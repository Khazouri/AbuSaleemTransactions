<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Department;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionStatus;
use App\Models\TransactionType;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_a_non_manager_is_refused_the_assigned_manager_may_act_and_r08_may_act_with_no_manager_assigned(): void
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
        $transaction = $this->newTransaction('direct_manager_review', 'in_review', $employee->id);
        try {
            $service->transition($transaction, 'forward', $stranger);
            $this->fail('An unrelated user must not be able to act as the manager.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        // The assigned manager may act.
        $transaction = $service->transition($transaction->refresh(), 'forward', $manager);
        $this->assertSame('administrative_routing', $transaction->currentStage->code);

        // R08 may act even though this employee has no manager assigned at all.
        $unmanagedEmployee = $this->userWithRole('R01');
        $orphanTransaction = $this->newTransaction('direct_manager_review', 'in_review', $unmanagedEmployee->id);
        $moved = $service->transition($orphanTransaction, 'forward', $admin);
        $this->assertSame('administrative_routing', $moved->currentStage->code);
    }

    public function test_a_soft_deleted_or_inactive_manager_falls_through_to_the_r08_override_rather_than_500ing(): void
    {
        $this->seed(DatabaseSeeder::class);

        $employee = $this->userWithRole('R01');
        $inactiveManager = User::factory()->create(['is_active' => false]);
        $employee->manager_id = $inactiveManager->id;
        $employee->save();

        $admin = $this->userWithRole('R08');
        $service = app(WorkflowService::class);
        $transaction = $this->newTransaction('direct_manager_review', 'in_review', $employee->id);

        // The deactivated manager can no longer act at all (WorkflowService
        // refuses any inactive actor outright, before rule matching even
        // starts) — separately, actorIsCreatorsActiveManager() is what stops
        // a DIFFERENT actor from resolving this dangling manager_id as a
        // still-live manager link; both guard the same "no 500, no silent
        // stranding" property from two angles.
        try {
            $service->transition($transaction, 'forward', $inactiveManager);
            $this->fail('A deactivated manager must not be able to act.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('لا يمكن لمستخدم غير نشط تنفيذ إجراء سير العمل.', $exception->getMessage());
        }

        // ...but R08 can, with no 500 and no special-casing needed.
        $moved = $service->transition($transaction->refresh(), 'forward', $admin);
        $this->assertSame('administrative_routing', $moved->currentStage->code);
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

            $transaction = $this->newTransaction('administrative_routing', 'in_review', $employee->id);
            $moved = $service->transition($transaction, $action, $manager);

            $this->assertSame('receive_and_register', $moved->currentStage->code);
            $this->assertSame($statusCode, $moved->status->code);
        }
    }

    public function test_each_register_row_requires_both_its_role_and_its_matching_routed_status(): void
    {
        $this->seed(DatabaseSeeder::class);

        $service = app(WorkflowService::class);
        $registrar = $this->userWithRole('R10'); // Diwan deputy
        $transaction = $this->newTransaction('receive_and_register', 'routed_to_hr'); // routed to HR, not Diwan

        // R10 holds the right ROLE for a Diwan-routed file, but this file was
        // routed to HR — the status gate must refuse it. This is the
        // assertion that proves routing is enforced, not merely advisory.
        try {
            $service->transition($transaction, 'register', $registrar);
            $this->fail('R10 must not be able to register an HR-routed file.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        // The matching registrar (R05/HR) succeeds on the same file.
        $hrRegistrar = $this->userWithRole('R05');
        $moved = $service->transition($transaction->refresh(), 'register', $hrRegistrar);
        $this->assertSame('requirements_check', $moved->currentStage->code);
        $this->assertSame('registered', $moved->status->code);

        // And the reverse pairing (R05 attempting a Diwan-routed file) is
        // equally refused, confirming this isn't a one-way accident.
        $diwanTransaction = $this->newTransaction('receive_and_register', 'routed_to_diwan');
        try {
            $service->transition($diwanTransaction, 'register', $hrRegistrar);
            $this->fail('R05 must not be able to register a Diwan-routed file.');
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
        $transaction = $this->newTransaction('direct_manager_review', 'in_review', $employee->id);

        try {
            $service->transition($transaction, 'return_to_employee', $manager);
            $this->fail('return_to_employee should require a comment.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('يجب إدخال سبب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        $moved = $service->transition($transaction->refresh(), 'return_to_employee', $manager, 'بيانات ناقصة.');
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

            $transaction = $this->newTransaction($managerGatedStage, 'in_review', $employee->id);
            $moved = $service->transition($transaction, 'cancel', $manager, 'ألغيت بناء على طلب الموظف.');
            $this->assertSame($managerGatedStage, $moved->currentStage->code);
            $this->assertSame('cancelled', $moved->status->code);
        }

        foreach (['R05', 'R09', 'R10'] as $registrarRole) {
            $registrar = $this->userWithRole($registrarRole);
            $transaction = $this->newTransaction('receive_and_register', 'routed_to_hr');
            $moved = $service->transition($transaction, 'cancel', $registrar, 'ألغيت.');
            $this->assertSame('receive_and_register', $moved->currentStage->code);
            $this->assertSame('cancelled', $moved->status->code);
        }
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
        $transaction = $this->newTransaction('direct_manager_review', 'in_review', $employee->id);

        // The UI must not offer a button execution would refuse...
        $this->assertFalse($service->availableActions($transaction, $stranger)->contains('forward'));

        // ...and must not hide one execution would accept.
        $this->assertTrue($service->availableActions($transaction, $manager)->contains('forward'));
    }

    private function newTransaction(
        string $stageCode = 'receive_from_municipality',
        string $statusCode = 'new',
        ?int $createdByUserId = null,
    ): Transaction {
        return Transaction::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'اختبار المسار الإداري الجديد',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'transaction_type_id' => TransactionType::where('code', 'PROM')->value('id'),
            'status_id' => TransactionStatus::where('code', $statusCode)->value('id'),
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
