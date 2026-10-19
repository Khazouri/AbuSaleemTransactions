<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Decision;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestSuspension;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PassesControlGates;
use Tests\TestCase;

/**
 * Stage 78 — [D] Appendix 63's four control gates ("وتمنع المنظومة
 * الإلكترونية الانتقال إذا كانت متطلبات البوابة غير مكتملة") and Art. 105's
 * إيقاف إجرائي.
 *
 * What this file owns is gate 1 and Art. 105. The committee meets on paper
 * since 2026-10-18, so the gates that rode the in-system sitting (readiness,
 * the minutes' Appendix 8 checks, Art. 103's soundness list) went with it.
 */
class ControlGateTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    // --- بوابة 1 — قبل القيد ------------------------------------------

    public function test_the_registration_hop_is_refused_until_every_required_document_is_answered(): void
    {
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAtRequirementsCheck();

        $this->actingAs($reviewer, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/transition", [
                'action' => 'approve',
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.action.0',
                'لا يجوز قيد المعاملة قبل استيفاء بوابة الرقابة الأولى: التحقق من صحة الوقائع واكتمال الوثائق.',
            );

        // Still at the same stage, and no قيد was granted.
        $this->assertSame('requirements_check', $requestRecord->fresh()->currentStage->code);
    }

    // The per-file verdicts are DocumentReviewTest's; this file keeps the
    // gate's own first refusal above and the facts half below.

    public function test_the_facts_attestation_is_required_and_the_gate_passes_once_it_is_given(): void
    {
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAtRequirementsCheck();
        // No file is attached, so the documents half has nothing to judge.
        $answers = [];

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/intake-gate", [
                'documents' => $answers,
                'facts_verified' => false,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.control_gates.intake.refusal',
                'لا يجوز القيد قبل التحقق من صحة الوقائع والبيانات المقدمة.',
            );

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/intake-gate", [
                'documents' => $answers,
                'facts_verified' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.control_gates.intake.refusal', null);

        $this->actingAs($reviewer, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/transition", [
                'action' => 'approve',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'registered');
    }

    /**
     * The queue is the primary way `requirements_check` gets approved, so
     * gating only the generic endpoint would leave this one wide open — the
     * dual-path trap Stages 54, 70 and 77 each had to close the same way.
     */
    public function test_the_reviewer_approval_queue_is_gated_by_the_same_predicate(): void
    {
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAtRequirementsCheck();

        $this->actingAs($reviewer, 'sanctum')
            ->post("/api/approvals/reviewer/{$requestRecord->id}", [], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.request.0',
                'لا يجوز قيد المعاملة قبل استيفاء بوابة الرقابة الأولى: التحقق من صحة الوقائع واكتمال الوثائق.',
            );

        $this->passIntakeGate($requestRecord);

        $this->actingAs($reviewer, 'sanctum')
            ->post("/api/approvals/reviewer/{$requestRecord->id}", [], ['Accept' => 'application/json'])
            ->assertOk();
    }

    /**
     * Only the قيد hop is gated. Declaring عدم اختصاص or refusing a file
     * formally does not grant a قيد, so neither should have to wait for the
     * documents — and `return_missing_docs` is the escape hatch Art. 19
     * provides for the incomplete file this gate refuses.
     */
    public function test_the_non_registration_outcomes_at_the_same_stage_are_not_gated(): void
    {
        $reviewer = $this->userWithRole('R02');

        foreach (['declare_no_jurisdiction', 'return_missing_docs'] as $action) {
            $requestRecord = $this->requestAtRequirementsCheck();

            $this->actingAs($reviewer, 'sanctum')
                ->postJson("/api/requests/{$requestRecord->id}/transition", [
                    'action' => $action,
                    'comment' => 'سبب الإجراء.',
                ])
                ->assertOk();
        }
    }

    // --- no execution leg --------------------------------------------

    /**
     * 2026-10-18 — no execution leg (user decision): the dean's
     * final_approved -> in_execution step is gone, and an approved file goes
     * straight to its archives.
     */
    public function test_a_final_approved_file_has_no_step_into_execution(): void
    {
        [, $requestRecord] = $this->finalApprovedRequest();

        $this->actingAs($this->userWithRole('R07'), 'sanctum')
            ->post("/api/approvals/final/{$requestRecord->id}", [], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame('final_approved', $requestRecord->fresh()->status->code);
    }

    // --- Art. 105 — الإيقاف الإجرائي ----------------------------------

    /**
     * "يوقف التنفيذ فورًا **من الناحية الإجرائية**" — a hold, not a move, so
     * no stage log is written.
     */
    public function test_suspending_is_status_only_and_writes_no_stage_log(): void
    {
        $suspender = $this->userWithRole('R02');
        [, $requestRecord] = $this->finalApprovedRequest();

        $this->actingAs($suspender, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/suspend", [
                'ground' => 'incorrect_material_fact',
                'detail' => 'تاريخ المباشرة المثبت لا يطابق كشف الخدمة.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'execution_suspended')
            ->assertJsonPath('data.current_stage.code', 'final_approval_archiving')
            ->assertJsonPath('data.suspensions.0.ground', 'incorrect_material_fact');

        $this->assertSame(0, $requestRecord->stageLogs()->count());
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $requestRecord->id,
            'to_status_id' => RequestStatus::where('code', 'execution_suspended')->value('id'),
        ]);
    }

    public function test_an_open_suspension_blocks_approval_through_both_entry_points(): void
    {
        $suspender = $this->userWithRole('R02');
        $approver = $this->userWithRole('R07');
        [, $requestRecord] = $this->finalApprovedRequest();

        $this->actingAs($suspender, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/suspend", [
                'ground' => 'document_in_doubt',
                'detail' => 'المؤهل المرفق محل شك.',
            ])
            ->assertOk();

        $this->actingAs($approver, 'sanctum')
            ->post("/api/approvals/final/{$requestRecord->id}", [], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.request.0',
                'المعاملة موقوفة إجرائياً وفق المادة 105 حتى تُستكمل المراجعة القانونية.',
            );

        $this->actingAs($approver, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/transition", [
                'action' => 'approve',
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.action.0',
                'المعاملة موقوفة إجرائياً وفق المادة 105 حتى تُستكمل المراجعة القانونية.',
            );

        $this->assertDatabaseCount('approvals', 0);
    }

    /**
     * "ويحال الموضوع للمراجعة القانونية" enforced rather than narrated: a
     * suspension raised and dropped by the same hand with nothing examined in
     * between is exactly the شكلية Art. 104 rules out.
     */
    public function test_a_suspension_cannot_be_lifted_before_a_legal_review_reports_back(): void
    {
        $suspender = $this->userWithRole('R02');
        $legal = $this->userWithRole('R11');
        [, $requestRecord] = $this->finalApprovedRequest();

        $this->actingAs($suspender, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/suspend", [
                'ground' => 'incorrect_material_fact',
                'detail' => 'الدرجة الوظيفية المثبتة غير صحيحة.',
            ])
            ->assertOk();

        $this->actingAs($suspender, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/suspend/lift", [
                'resolution_action' => 'fact_confirmed',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يرفع الإيقاف قبل إثبات مراجعة قانونية بعد تاريخ الإيقاف.');

        // The suspended file reaches the legal member's own queue, which is
        // what makes the article's referral real.
        $this->actingAs($legal, 'sanctum')
            ->getJson('/api/legal-reviews')
            ->assertOk()
            ->assertJsonPath('data.0.id', $requestRecord->id);

        $this->actingAs($legal, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                'recommendation' => 'approve',
                'approving_authority' => 'dean',
                'legal_note' => 'راجعت الدرجة وثبتت صحتها.',
            ])
            ->assertCreated();

        $this->actingAs($suspender, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/suspend/lift", [
                'resolution_action' => 'fact_confirmed',
            ])
            ->assertOk()
            // Restored exactly where Art. 105 interrupted it.
            ->assertJsonPath('data.status.code', 'final_approved')
            ->assertJsonPath('data.current_stage.code', 'final_approval_archiving');
    }

    public function test_a_substantive_lift_sends_the_matter_back_to_the_committee(): void
    {
        $suspender = $this->userWithRole('R02');
        $legal = $this->userWithRole('R11');
        [, $requestRecord] = $this->finalApprovedRequest();

        $this->actingAs($suspender, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/suspend", [
                'ground' => 'document_in_doubt',
                'detail' => 'المستند المؤيد غير صحيح.',
            ])
            ->assertOk();

        $this->actingAs($legal, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                'recommendation' => 'decline',
                'approving_authority' => 'dean',
                'legal_note' => 'المستند غير صالح ويجب إعادة العرض.',
            ])
            ->assertCreated();

        $this->actingAs($suspender, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/suspend/lift", [
                'resolution_action' => 'referred_to_committee',
                'resolution_note' => 'يعاد الموضوع للجنة.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'reopened_for_representation')
            ->assertJsonPath('data.current_stage.code', 'receive_from_committee');

        // reopenAtStage() leaves the same audit trail an ordinary move would.
        $this->assertDatabaseHas('request_stage_logs', [
            'request_id' => $requestRecord->id,
            'action' => 'article_105_restudy',
        ]);
    }

    public function test_two_suspension_rounds_accumulate_rather_than_overwrite(): void
    {
        $suspender = $this->userWithRole('R02');
        $legal = $this->userWithRole('R11');
        [, $requestRecord] = $this->finalApprovedRequest();

        foreach (['أول شك في المستند.', 'شك ثانٍ بعد الاستئناف.'] as $detail) {
            $this->actingAs($suspender, 'sanctum')
                ->patchJson("/api/requests/{$requestRecord->id}/suspend", [
                    'ground' => 'document_in_doubt',
                    'detail' => $detail,
                ])
                ->assertOk();

            $this->actingAs($legal, 'sanctum')
                ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                    'recommendation' => 'approve',
                    'approving_authority' => 'dean',
                    'legal_note' => 'راجعت المستند.',
                ])
                ->assertCreated();

            $this->actingAs($suspender, 'sanctum')
                ->patchJson("/api/requests/{$requestRecord->id}/suspend/lift", [
                    'resolution_action' => 'fact_confirmed',
                ])
                ->assertOk();
        }

        $this->assertSame(2, RequestSuspension::where('request_id', $requestRecord->id)->count());
        $this->assertSame('أول شك في المستند.', RequestSuspension::where('request_id', $requestRecord->id)
            ->orderBy('id')->value('detail'));
    }

    public function test_a_suspension_is_refused_outside_the_approval_and_execution_window(): void
    {
        $suspender = $this->userWithRole('R02');
        $requestRecord = $this->requestAtRequirementsCheck();

        $this->actingAs($suspender, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/suspend", [
                'ground' => 'incorrect_material_fact',
                'detail' => 'بيانات غير صحيحة.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يوقف التنفيذ إلا لمعاملة في دورة الاعتماد أو التنفيذ.');
    }

    /**
     * The sixth instance of the same visibility class Stages 47/68/75/76/77
     * each had to close: the suspender must be able to open the very file
     * they are meant to act on.
     */
    public function test_the_suspender_can_open_a_final_approved_request_they_did_not_create(): void
    {
        $suspender = $this->userWithRole('R02');
        [, $requestRecord] = $this->finalApprovedRequest();

        $this->assertNotSame($suspender->id, $requestRecord->created_by_user_id);

        $this->actingAs($suspender, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();

        // Grant-bounded, not a general read of the pipeline.
        $this->actingAs($this->userWithRole('R04'), 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertStatus(404);
    }

    public function test_reopening_clears_the_gate_records_but_keeps_the_suspension_register(): void
    {
        $actor = $this->userWithRole('R02');
        [, $requestRecord] = $this->finalApprovedRequest();
        $this->passIntakeGate($requestRecord);

        RequestSuspension::create([
            'request_id' => $requestRecord->id,
            'ground' => 'document_in_doubt',
            'detail' => 'جولة سابقة.',
            'suspended_by_user_id' => $actor->id,
            'suspended_at' => now()->subDay(),
            'resolution_action' => 'fact_confirmed',
            'resolved_by_user_id' => $actor->id,
            'resolved_at' => now()->subHours(2),
        ]);

        $requestRecord->update(['status_id' => RequestStatus::where('code', 'completed_closed')->value('id')]);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/reopen", [
                'reason_code' => 'new_document',
                'target_stage_id' => WorkflowStage::where('code', 'requirements_check')->value('id'),
            ])
            ->assertOk()
            ->assertJsonPath('data.control_gates.intake.record', null)
            // The register survives — earlier rounds staying readable is the
            // whole reason it is a history rather than a column.
            ->assertJsonCount(1, 'data.suspensions');
    }

    // --- fixtures ------------------------------------------------------

    private function requestAtRequirementsCheck(): Request
    {
        return Request::create([
            'reference_number' => null,
            'intake_receipt_number' => 'PM-RCV/2026/'.str_pad((string) (Request::count() + 1), 6, '0', STR_PAD_LEFT),
            'title' => 'طلب عند فحص الاكتمال',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_review')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'requirements_check')->value('id'),
            'created_by_user_id' => $this->userWithRole('R01')->id,
            'submitted_at' => now(),
            'decision_grade' => 10,
            // Stage 54's own half of this same gate, so these scenarios stay
            // about the documents rather than about Art. 45.
            'jurisdiction_test' => $this->jurisdictionAnswers(),
        ]);
    }

    /**
     * A file the authority approved, standing on `final_approval_archiving`
     * with the committee's decision behind it — the window Art. 105's
     * suspension acts in.
     *
     * @return array{0: Meeting, 1: Request}
     */
    private function finalApprovedRequest(): array
    {
        $rapporteur = $this->userWithRole('R02');
        $committee = Committee::create(['name_ar' => 'لجنة بوابات الرقابة']);
        $committee->members()->create(['user_id' => $rapporteur->id, 'seat' => 'rapporteur']);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'meeting_number' => 'PM-MTG/2026/01',
            'title' => 'اجتماع بوابات الرقابة',
            'scheduled_at' => now()->subDay(),
            'status' => 'completed',
            'created_by_user_id' => $rapporteur->id,
        ]);

        $requestRecord = Request::create([
            'reference_number' => 'PM-COM/2026/'.str_pad((string) (Request::count() + 1), 4, '0', STR_PAD_LEFT),
            'title' => 'معاملة معتمدة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'final_approved')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'final_approval_archiving')->value('id'),
            'created_by_user_id' => $this->userWithRole('R01')->id,
            'submitted_at' => now()->subMonth(),
            'decision_grade' => 10,
            'jurisdiction_test' => $this->jurisdictionAnswers(),
        ]);

        $agendaItem = MeetingRequest::create([
            'meeting_id' => $meeting->id,
            'request_id' => $requestRecord->id,
            'agenda_order' => 1,
            'item_state' => 'complete',
        ]);
        // The committee decides on paper since 2026-10-18 (PaperMeetingResultsTest).
        Decision::create([
            'meeting_request_id' => $agendaItem->id,
            'outcome' => 'approve',
            'instrument' => 'recommendation',
            'decided_by_user_id' => $rapporteur->id,
            'decided_at' => now()->subDay(),
        ]);

        return [$meeting->fresh(), $requestRecord->fresh()];
    }

    /** @return array<string, mixed> */
    private function jurisdictionAnswers(): array
    {
        return [
            'has_legal_basis' => true,
            'employee_covered' => true,
            'within_municipal_jurisdiction' => true,
            'committee_decides' => true,
            'final_approval_authority' => 'عميد البلدية',
            'requires_central_approval' => false,
        ];
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'department_id' => Department::where('code', 'ADM')->value('id'),
        ]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
