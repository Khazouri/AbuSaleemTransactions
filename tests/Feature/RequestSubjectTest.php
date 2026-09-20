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
use App\Notifications\ActionRequiredNotification;
use App\Notifications\RequestNoticeNotification;
use App\Services\CommitteeStatusService;
use App\Services\IntakeGateService;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\PassesControlGates;
use Tests\TestCase;

/**
 * Stage 95 — صاحب العلاقة, the employee a request is ABOUT.
 *
 * [D] Appendix 6 names every party relative to that employee: row 2's
 * الرئيس المباشر is THEIR manager, row 3's ملف وظيفي is THEIR file, Art. 101's
 * «يتم إشعار الموظف» is THEM being told. Until this stage the system carried
 * only `created_by_user_id`, so a file raised on an employee's behalf routed
 * to the clerk's manager and notified the clerk — silently, because a
 * self-filed request makes the two indistinguishable.
 *
 * The tests below are written the other way round on purpose: the subject and
 * the filer are DIFFERENT people in almost every one, because that is the only
 * arrangement in which a wrong answer is visible at all.
 */
class RequestSubjectTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;

    /**
     * The whole reason this stage needed no fixture sweep: a request that
     * states no subject is about whoever filed it, and the model says so on
     * every save rather than at each of twenty read sites.
     */
    public function test_a_request_with_no_stated_subject_is_about_its_filer(): void
    {
        $this->seed(DatabaseSeeder::class);

        $employee = $this->userWithRole('R01');

        $atCreate = $this->newRequest(createdByUserId: $employee->id);
        $this->assertSame($employee->id, $atCreate->subject_user_id);

        // Several fixtures — and the reopen path — assign the creator after
        // the row exists, which a `creating`-only hook would have missed.
        $afterwards = $this->newRequest();
        $this->assertNull($afterwards->subject_user_id);

        $afterwards->created_by_user_id = $employee->id;
        $afterwards->save();

        $this->assertSame($employee->id, $afterwards->refresh()->subject_user_id);
    }

    /**
     * The stage's own Done-when: row 2's مسؤول is the SUBJECT's الرئيس
     * المباشر, in code. The filer's own manager — the person this gate named
     * before the stage — is now refused.
     */
    public function test_the_manager_gate_answers_to_the_subjects_manager_not_the_filers(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$subject, $subjectsManager] = $this->employeeWithManager();
        [$clerk, $clerksManager] = $this->employeeWithManager('R02');

        $requestRecord = $this->newRequest('direct_manager_review', 'in_review', $clerk->id, $subject->id);
        $service = app(WorkflowService::class);

        try {
            $service->transition($requestRecord, 'forward', $clerksManager);
            $this->fail('The filer\'s own manager must not be able to delegate a file about someone else.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        $moved = $service->transition($requestRecord->refresh(), 'forward', $subjectsManager);

        $this->assertSame(
            WorkflowStage::where('code', 'administrative_routing')->value('id'),
            $moved->current_stage_id,
        );
    }

    /**
     * The gate, the workspace and the prompt are three separate copies of the
     * same rule. A manager who may act but cannot open the file is a dead end,
     * and one who is told but may not act is noise — so all three must resolve
     * the same person.
     */
    public function test_the_subjects_manager_can_open_the_file_and_is_the_one_prompted(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$subject, $subjectsManager] = $this->employeeWithManager();
        [$clerk, $clerksManager] = $this->employeeWithManager('R02');

        $requestRecord = $this->newRequest('receive_from_municipality', 'new', $clerk->id, $subject->id);

        Notification::fake();
        app(WorkflowService::class)->applySystemTransition($requestRecord, 'receive_from_municipality', 'submit', $clerk);

        // Prompted: the subject's manager, and only theirs.
        Notification::assertSentTo($subjectsManager, ActionRequiredNotification::class);
        Notification::assertNotSentTo($clerksManager, ActionRequiredNotification::class);

        // Reachable: the same manager can actually open what they were told about.
        $this->actingAs($subjectsManager, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();

        $this->actingAs($clerksManager, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertNotFound();
    }

    /**
     * Art. 101's «يتم إشعار الموظف» and Art. 102's «صاحب العلاقة» both name the
     * employee the matter concerns, so the notice goes to them and the vote
     * tally is withheld from them — not from the clerk who filed.
     */
    public function test_article_101s_notice_reaches_the_subject_rather_than_the_filer(): void
    {
        $this->seed(DatabaseSeeder::class);

        $subject = $this->userWithRole('R01');
        $clerk = $this->userWithRole('R02');
        $requestRecord = $this->newRequest('receive_from_committee', 'ready', $clerk->id, $subject->id);

        Notification::fake();
        app(CommitteeStatusService::class)->move($requestRecord, 'require_completion', $clerk, 'ينقص مستند');

        Notification::assertSentTo($subject, RequestNoticeNotification::class);
        Notification::assertNotSentTo($clerk, RequestNoticeNotification::class);

        // And the delivered register reads back against the same person.
        $this->assertSame(
            $subject->id,
            $requestRecord->refresh()->subject_user_id,
        );
    }

    /**
     * [D] Appendix 16 searches «برقم الموظف». Keyed on the filer, a clerk who
     * files one promotion could never file the next employee's — the feature
     * this stage enables would have shipped broken.
     */
    public function test_appendix_16s_search_is_per_subject_not_per_filer(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        $clerk = $this->clerkWhoMayFileForOthers();
        $first = $this->userWithRole('R01');
        $second = $this->userWithRole('R01');
        $type = RequestType::where('code', 'PROM')->firstOrFail();

        $this->actingAs($clerk, 'sanctum')
            ->post('/api/requests', $this->intakePayload($type, $first), ['Accept' => 'application/json'])
            ->assertCreated();

        // A different employee, same type: a fresh matter, not a duplicate.
        $this->actingAs($clerk, 'sanctum')
            ->post('/api/requests', $this->intakePayload($type, $second), ['Accept' => 'application/json'])
            ->assertCreated();

        // The same employee again, while their file is still open: refused.
        $this->actingAs($clerk, 'sanctum')
            ->post('/api/requests', $this->intakePayload($type, $first), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('request_type_id');

        $this->assertSame(2, Request::count());
    }

    /** Naming someone else is a grant, not something every filer may do. */
    public function test_filing_for_another_employee_needs_the_grant(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        $type = RequestType::where('code', 'PROM')->firstOrFail();
        $colleague = $this->userWithRole('R01');

        // R01 holds request_intake,add but not its `approve` tier.
        $employee = $this->userWithRole('R01');

        $this->actingAs($employee, 'sanctum')
            ->post('/api/requests', $this->intakePayload($type, $colleague), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject_user_id');

        $this->assertSame(0, Request::count());

        // Naming yourself needs nothing beyond the ordinary intake grant.
        $this->actingAs($employee, 'sanctum')
            ->post('/api/requests', $this->intakePayload($type, $employee), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.id', fn ($id) => Request::find($id)->subject_user_id === $employee->id);
    }

    /** The picker's options are offered to exactly the callers who may use it. */
    public function test_intake_options_offer_the_subject_picker_only_with_the_grant(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->actingAs($this->clerkWhoMayFileForOthers(), 'sanctum')
            ->getJson('/api/requests/intake-options')
            ->assertOk()
            ->assertJsonPath('data.may_file_for_others', true)
            ->assertJsonPath('data.subject_options', fn ($options) => count($options) > 0);

        $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->getJson('/api/requests/intake-options')
            ->assertOk()
            ->assertJsonPath('data.may_file_for_others', false)
            ->assertJsonPath('data.subject_options', []);
    }

    /**
     * «طلباتي» means mine to both of them: the employee the file is about is
     * tracking their own matter, and the clerk is tracking work they filed.
     */
    public function test_both_parties_see_the_file_on_the_tracking_screen(): void
    {
        $this->seed(DatabaseSeeder::class);

        $subject = $this->userWithRole('R01');
        $clerk = $this->userWithRole('R02');
        $stranger = $this->userWithRole('R01');

        $requestRecord = $this->newRequest('requirements_check', 'in_review', $clerk->id, $subject->id);

        foreach ([$subject, $clerk] as $party) {
            $this->actingAs($party, 'sanctum')
                ->getJson('/api/my-requests')
                ->assertOk()
                ->assertJsonPath('meta.total', 1);

            $this->actingAs($party, 'sanctum')
                ->getJson("/api/my-requests/{$requestRecord->id}")
                ->assertOk();
        }

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/my-requests/{$requestRecord->id}")
            ->assertNotFound();
    }

    /**
     * Appendix 19's separation of duties names مقدم الطلب, but an employee
     * attesting to the completeness of the file their own matter rests on is
     * the worse of the two cases.
     */
    public function test_the_subject_may_not_record_the_intake_gate_on_their_own_matter(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Holds the grant the gate rides, and is صاحب العلاقة of this file.
        $subject = $this->userWithRoles(['R01', 'R02']);
        $clerk = $this->userWithRole('R02');
        $requestRecord = $this->newRequest('requirements_check', 'in_review', $clerk->id, $subject->id);

        $this->actingAs($subject, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/intake-gate", $this->intakeGatePayload($requestRecord))
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يجوز لمقدّم الطلب أو صاحب العلاقة إثبات اكتمال الملف بنفسه.');

        $this->assertNull($requestRecord->refresh()->intake_gate);
    }

    /** [C] §6's بيانات الموظف tab shows the employee, not whoever typed it in. */
    public function test_the_workspace_reports_the_subject_beside_the_filer(): void
    {
        $this->seed(DatabaseSeeder::class);

        $subject = $this->userWithRole('R01');
        $clerk = $this->userWithRole('R02');
        $requestRecord = $this->newRequest('requirements_check', 'in_review', $clerk->id, $subject->id);

        $this->actingAs($clerk, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.subject.id', $subject->id)
            ->assertJsonPath('data.created_by.id', $clerk->id);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: User, 1: User} the employee and their active manager */
    private function employeeWithManager(string $roleCode = 'R01'): array
    {
        // The manager needs a role of its own, or the screen gate refuses
        // before RequestVisibility is ever consulted.
        $manager = $this->userWithRole('R01');
        $employee = $this->userWithRole($roleCode);
        $employee->manager_id = $manager->id;
        $employee->save();

        return [$employee, $manager];
    }

    /** @return array<string, mixed> */
    private function intakeGatePayload(Request $requestRecord): array
    {
        $gate = app(IntakeGateService::class);

        return [
            'documents' => array_fill_keys(array_keys($gate->requiredDocuments($requestRecord)), 'present'),
            'facts_verified' => true,
        ];
    }

    /** R02 holds request_intake's `approve` tier, seeded by Stage 95. */
    private function clerkWhoMayFileForOthers(): User
    {
        return $this->userWithRole('R02');
    }

    /** @return array<string, mixed> */
    private function intakePayload(RequestType $type, User $subject): array
    {
        return [
            'title' => 'طلب نيابة عن موظف',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => $type->id,
            'decision_grade' => 9,
            'subject_user_id' => $subject->id,
            'attachments' => $this->mandatoryAttachments($type),
        ];
    }

    private function newRequest(
        string $stageCode = 'receive_from_municipality',
        string $statusCode = 'new',
        ?int $createdByUserId = null,
        ?int $subjectUserId = null,
    ): Request {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'اختبار صاحب العلاقة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'submitted_at' => now(),
            'created_by_user_id' => $createdByUserId,
            'subject_user_id' => $subjectUserId,
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        return $this->userWithRoles([$roleCode]);
    }

    /** @param  list<string>  $roleCodes */
    private function userWithRoles(array $roleCodes): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::whereIn('code', $roleCodes)->pluck('id'));

        return $user;
    }
}
