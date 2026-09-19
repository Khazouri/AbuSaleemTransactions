<?php

namespace Tests\Feature;

use App\Models\Attachment;
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
use App\Services\IntakeGateService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PassesControlGates;
use Tests\TestCase;

/**
 * Stage 78 — [D] Appendix 63's four control gates ("وتمنع المنظومة
 * الإلكترونية الانتقال إذا كانت متطلبات البوابة غير مكتملة"), Art. 103's
 * قائمة فحص سلامة القرار and Art. 105's إيقاف إجرائي.
 *
 * Gate 2 (قبل جدول الأعمال) is Stage 33's MeetingReadinessTest and gate 3
 * (قبل الاعتماد) rides the minutes lifecycle, so both are covered where they
 * live; what this file owns is gate 1, Art. 103, Art. 105, and the Appendix 8
 * refusals that are new here.
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
                'لا يجوز إحالة الملف إلى مراجعة المقرر قبل استيفاء بوابة الرقابة الأولى: التحقق من صحة الوقائع واكتمال الوثائق.',
            );

        // Still at the same stage, and no قيد was granted.
        $this->assertSame('requirements_check', $requestRecord->fresh()->currentStage->code);
    }

    /**
     * Appendix 57 writes its conditional items as an inline qualifier rather
     * than a separate list, so "لا ينطبق" is honest for one and refused for
     * an item the source states unconditionally.
     */
    public function test_a_missing_document_refuses_and_not_applicable_is_allowed_only_where_the_source_conditions_it(): void
    {
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAtRequirementsCheck();

        $documents = app(IntakeGateService::class)->requiredDocuments($requestRecord);
        $unconditional = null;
        $conditional = null;
        foreach ($documents as $key => $document) {
            if ($document['conditional'] && $conditional === null) {
                $conditional = $key;
            }
            if (! $document['conditional'] && $unconditional === null) {
                $unconditional = $key;
            }
        }

        $this->assertNotNull($unconditional, 'the seeded matrix should carry at least one unconditional document');
        $this->assertNotNull($conditional, 'the seeded matrix should carry at least one conditional document');

        $answers = array_fill_keys(array_keys($documents), 'present');

        // A missing document refuses, quoting the document back.
        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/intake-gate", [
                'documents' => [...$answers, $unconditional => 'missing'],
                'facts_verified' => true,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.control_gates.intake.refusal',
                'لا يجوز متابعة الإجراء قبل اكتمال المستندات المطلوبة. المستند الناقص: '.$documents[$unconditional]['ar'],
            );

        // Waiving an unconditional one refuses too.
        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/intake-gate", [
                'documents' => [...$answers, $unconditional => 'not_applicable'],
                'facts_verified' => true,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.control_gates.intake.refusal',
                'هذا المستند مطلوب في جميع الحالات ولا يجوز اعتباره غير منطبق: '.$documents[$unconditional]['ar'],
            );

        // Waiving a conditional one is honest, and the gate passes.
        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/intake-gate", [
                'documents' => [...$answers, $conditional => 'not_applicable'],
                'facts_verified' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.control_gates.intake.refusal', null);
    }

    public function test_the_facts_attestation_is_required_and_the_gate_passes_once_it_is_given(): void
    {
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAtRequirementsCheck();
        $answers = array_fill_keys(
            array_keys(app(IntakeGateService::class)->requiredDocuments($requestRecord)),
            'present',
        );

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/intake-gate", [
                'documents' => $answers,
                'facts_verified' => false,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.control_gates.intake.refusal',
                'لا يجوز متابعة الإجراء قبل التحقق من صحة الوقائع والبيانات المقدمة.',
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
                'لا يجوز إحالة الملف إلى مراجعة المقرر قبل استيفاء بوابة الرقابة الأولى: التحقق من صحة الوقائع واكتمال الوثائق.',
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

    // --- بوابة 3 — قبل الاعتماد (Appendix 8) --------------------------

    /**
     * The محضر's `content` freezes at generate() time, so a document compiled
     * before the agenda changed no longer says what the meeting was. Until
     * this gate, generate → change the agenda → approve was a clean path to an
     * approved محضر describing a sitting that did not happen.
     */
    public function test_minutes_compiled_before_the_agenda_changed_cannot_be_approved(): void
    {
        [$head, , $meeting] = $this->committeeWithApprovableMinutes();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/generate")
            ->assertOk();

        // The agenda moves after the snapshot was taken.
        $meeting->agendaItems()->create([
            'item_type' => 'administrative',
            'subject' => 'بند أضيف بعد إنشاء المحضر',
            'agenda_order' => 9,
            'item_state' => 'complete',
        ]);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/review", $this->minutesApprovalPayload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يحال المحضر للاعتماد قبل التحقق من: تطابق البنود مع جدول الأعمال');

        // Regenerating against the current agenda clears it.
        $this->actingAs($head, 'sanctum')->postJson("/api/meetings/{$meeting->id}/minutes/generate")->assertOk();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/review", $this->minutesApprovalPayload())
            ->assertOk();
    }

    public function test_the_one_reviewer_answered_quality_check_refuses_when_it_is_no(): void
    {
        [$head, , $meeting] = $this->committeeWithApprovableMinutes();

        $this->actingAs($head, 'sanctum')->postJson("/api/meetings/{$meeting->id}/minutes/generate")->assertOk();

        $this->actingAs($head, 'sanctum')
            ->postJson(
                "/api/meetings/{$meeting->id}/minutes/review",
                $this->minutesApprovalPayload(['quality_checks' => ['no_internal_contradictions' => false]]),
            )
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يحال المحضر للاعتماد قبل التحقق من: خلو المحضر من تعارضات داخلية');
    }

    /**
     * All sixteen are stored, so the record reads as Appendix 8's own list.
     * The sixteenth carries its honest value rather than a tick: the signature
     * lifecycle is what enforces it, and at review time nobody has signed yet.
     */
    public function test_an_approved_minutes_records_all_sixteen_appendix_8_checks(): void
    {
        [$head, , $meeting] = $this->committeeWithApprovableMinutes();

        $this->actingAs($head, 'sanctum')->postJson("/api/meetings/{$meeting->id}/minutes/generate")->assertOk();

        $response = $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/review", $this->minutesApprovalPayload())
            ->assertOk();

        $checks = $response->json('data.quality_checks');
        $this->assertCount(16, $checks);
        $this->assertSame('yes', $checks['items_match_agenda']);
        $this->assertSame('yes', $checks['no_internal_contradictions']);
        $this->assertSame('enforced_by_signature_lifecycle', $checks['signatures_complete']);
    }

    // --- Art. 103 — قائمة فحص سلامة القرار ----------------------------

    public function test_execution_is_refused_until_the_soundness_checklist_is_recorded(): void
    {
        [, $requestRecord] = $this->requestReadyForExecution();
        $approver = $this->userWithRole('R07');

        $this->actingAs($approver, 'sanctum')
            ->post("/api/approvals/final/{$requestRecord->id}", [], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.request.0',
                'لا تحال النتيجة للتنفيذ قبل استيفاء قائمة فحص سلامة القرار (المادة 103).',
            );

        $this->certifySoundness($requestRecord);

        $this->actingAs($approver, 'sanctum')
            ->post("/api/approvals/final/{$requestRecord->id}", [], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'in_execution');
    }

    public function test_a_single_no_on_an_attested_check_refuses_and_quotes_it_back(): void
    {
        $certifier = $this->userWithRole('R02');
        [, $requestRecord] = $this->requestReadyForExecution();

        $this->actingAs($certifier, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/execution-soundness", $this->soundnessPayload([
                'employee_name_correct' => 'no',
            ]))
            ->assertOk()
            ->assertJsonPath(
                'data.control_gates.execution_soundness.refusal',
                'لا تحال النتيجة للتنفيذ قبل التحقق من: صحة اسم الموظف',
            );
    }

    /**
     * Art. 104's point, made mechanical: the eight derived checks are read
     * from real state, so answering "yes" cannot make an unsigned محضر signed.
     */
    public function test_a_derived_check_cannot_be_ticked_away(): void
    {
        $certifier = $this->userWithRole('R02');
        [$meeting, $requestRecord] = $this->requestReadyForExecution();

        // Take the محضر back out of `approved` — Art. 103's توقيع المحضر now
        // fails, and no answer the certifier gives can change that.
        $meeting->meetingMinutes()->update(['status' => 'draft']);

        $response = $this->actingAs($certifier, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/execution-soundness", $this->soundnessPayload())
            ->assertOk();

        $this->assertSame('no', $response->json('data.control_gates.execution_soundness.record.minutes_signed'));
        $this->assertSame(
            'لا تحال النتيجة للتنفيذ قبل التحقق من: توقيع المحضر',
            $response->json('data.control_gates.execution_soundness.refusal'),
        );
    }

    // --- Art. 105 — الإيقاف الإجرائي ----------------------------------

    /**
     * "يوقف التنفيذ فورًا **من الناحية الإجرائية**" — a hold, not a move, so
     * no stage log is written.
     */
    public function test_suspending_is_status_only_and_writes_no_stage_log(): void
    {
        $suspender = $this->userWithRole('R02');
        [, $requestRecord] = $this->requestReadyForExecution();

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
        [, $requestRecord] = $this->requestReadyForExecution();
        $this->certifySoundness($requestRecord);

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
        [, $requestRecord] = $this->requestReadyForExecution();

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
                'verdict' => 'sound_ready',
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
        [, $requestRecord] = $this->requestReadyForExecution();

        $this->actingAs($suspender, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/suspend", [
                'ground' => 'document_in_doubt',
                'detail' => 'المستند المؤيد غير صحيح.',
            ])
            ->assertOk();

        $this->actingAs($legal, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                'verdict' => 'needs_document',
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
        [, $requestRecord] = $this->requestReadyForExecution();

        foreach (['أول شك في المستند.', 'شك ثانٍ بعد الاستئناف.'] as $detail) {
            $this->actingAs($suspender, 'sanctum')
                ->patchJson("/api/requests/{$requestRecord->id}/suspend", [
                    'ground' => 'document_in_doubt',
                    'detail' => $detail,
                ])
                ->assertOk();

            $this->actingAs($legal, 'sanctum')
                ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                    'verdict' => 'sound_ready',
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
     * each had to close: the certifier and the suspender must be able to open
     * the very file they are meant to act on.
     */
    public function test_the_certifier_can_open_a_final_approved_request_they_did_not_create(): void
    {
        $certifier = $this->userWithRole('R02');
        [, $requestRecord] = $this->requestReadyForExecution();

        $this->assertNotSame($certifier->id, $requestRecord->created_by_user_id);

        $this->actingAs($certifier, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.control_gates.execution_soundness.derived.minutes_signed', true);

        // Grant-bounded, not a general read of the pipeline.
        $this->actingAs($this->userWithRole('R04'), 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertStatus(404);
    }

    public function test_reopening_clears_the_gate_records_but_keeps_the_suspension_register(): void
    {
        $actor = $this->userWithRole('R02');
        [, $requestRecord] = $this->requestReadyForExecution();
        $this->certifySoundness($requestRecord);
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
            ->assertJsonPath('data.control_gates.execution_soundness.record', null)
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

    /** A committee whose محضر can legitimately clear Appendix 8. */
    private function committeeWithApprovableMinutes(): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');

        $committee = Committee::create([
            'name_ar' => 'لجنة بوابات الرقابة',
            'quorum_type' => 'fraction',
            'quorum_numerator' => 1,
            'quorum_denominator' => 2,
            'quorum_comparator' => 'more_than',
            'quorum_text' => 'أكثر من نصف الأعضاء',
        ]);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'meeting_number' => 'PM-MTG/2026/01',
            'title' => 'اجتماع بوابات الرقابة',
            'scheduled_at' => now()->subDay(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);
        $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);

        return [$head, $member, $meeting];
    }

    /**
     * A complete file standing on `final_approval_archiving`: a structured
     * decision from a quorate sitting, a document, an approved محضر and Art.
     * 45's test — everything Art. 103's eight derived checks read.
     *
     * @return array{0: Meeting, 1: Request}
     */
    private function requestReadyForExecution(): array
    {
        [$head, $member, $meeting] = $this->committeeWithApprovableMinutes();

        $requestRecord = Request::create([
            'reference_number' => 'PM-COM/2026/'.str_pad((string) (Request::count() + 1), 4, '0', STR_PAD_LEFT),
            'title' => 'معاملة جاهزة للإحالة للتنفيذ',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'final_approved')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'final_approval_archiving')->value('id'),
            'created_by_user_id' => $this->userWithRole('R01')->id,
            'submitted_at' => now()->subMonth(),
            'decision_grade' => 10,
            'jurisdiction_test' => $this->jurisdictionAnswers(),
        ]);

        Attachment::create([
            'request_id' => $requestRecord->id,
            'disk' => 'local',
            'path' => 'attachments/fixture.pdf',
            'original_name' => 'مستند مؤيد.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'uploaded_by_user_id' => $requestRecord->created_by_user_id,
        ]);

        $agendaItem = MeetingRequest::create([
            'meeting_id' => $meeting->id,
            'request_id' => $requestRecord->id,
            'agenda_order' => 1,
            'item_state' => 'complete',
        ]);
        Decision::create([
            'meeting_request_id' => $agendaItem->id,
            'outcome' => 'approve',
            'instrument' => 'decision',
            'votes_approve_count' => 2,
            'decision_subject' => 'ترقية الموظف.',
            'decision_facts' => 'استوفى الموظف شروط الترقية.',
            'decision_basis' => 'المادة 135 من قانون علاقات العمل.',
            'decision_operative' => 'قررت اللجنة الموافقة على الترقية.',
            'decided_by_user_id' => $head->id,
            'decided_at' => now()->subDay(),
        ]);

        $this->approveMinutes($meeting, $head, [$head, $member]);

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
