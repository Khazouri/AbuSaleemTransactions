<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Decision;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\ClosesRequests;
use Tests\ExecutesRequests;
use Tests\TestCase;

/**
 * Stage 76 — [D] Appendix 70's دليل التنفيذ and النموذج 17's أمر تنفيذ قرار
 * وظيفي: "تم التنفيذ" stops being a claim and becomes evidence.
 */
class RequestExecutionTest extends TestCase
{
    use ClosesRequests;
    use ExecutesRequests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Appendix 70's own sentence, enforced: "لا يكفي أن تقول الجهة المنفذة (تم
     * التنفيذ) بل يجب إرفاق دليل التنفيذ."
     */
    public function test_execution_is_refused_without_an_attached_evidence_document(): void
    {
        [$executor, $meeting, $agendaItem, $requestRecord] = $this->executableOutput();

        $this->actingAs($executor, 'sanctum')
            ->postJson($this->executeUrl($meeting, $agendaItem), $this->executionPayload($requestRecord, ['evidence' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('evidence');

        $this->assertSame('in_execution', $requestRecord->fresh()->status->code);
        $this->assertNull($requestRecord->fresh()->executed_at);
    }

    /** A nominated document must be this request's own, not some other file's. */
    public function test_execution_is_refused_when_the_evidence_belongs_to_another_request(): void
    {
        [$executor, $meeting, $agendaItem, $requestRecord] = $this->executableOutput();
        $otherRequest = $this->requestRecord('final_approval_archiving', 'in_execution', $requestRecord->createdBy);
        $foreign = $this->executionEvidenceAttachment($otherRequest);

        $this->actingAs($executor, 'sanctum')
            ->postJson($this->executeUrl($meeting, $agendaItem), $this->executionPayload($requestRecord, [
                'evidence' => [['attachment_id' => $foreign->id, 'evidence_type' => 'administrative_decision']],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->assertSame('in_execution', $requestRecord->fresh()->status->code);
        $this->assertNull($foreign->fresh()->execution_evidence_type);
    }

    /**
     * The whole card, the whole checklist, and the evidence actually marked —
     * status-only, so the stage is untouched (Art. 38's 18 → 19).
     */
    public function test_a_substantiated_execution_records_the_card_the_checklist_and_the_evidence(): void
    {
        [$executor, $meeting, $agendaItem, $requestRecord] = $this->executableOutput();
        $evidence = $this->executionEvidenceAttachment($requestRecord);

        $this->actingAs($executor, 'sanctum')
            ->postJson($this->executeUrl($meeting, $agendaItem), $this->executionPayload($requestRecord, [
                'evidence' => [['attachment_id' => $evidence->id, 'evidence_type' => 'grade_amendment']],
            ]))
            ->assertOk()
            ->assertJsonPath('data.outputs.0.execution_status.code', 'executed')
            ->assertJsonPath('data.outputs.0.execution.executing_body', 'قسم شؤون الموظفين')
            ->assertJsonPath('data.outputs.0.execution.approving_body', 'عميد البلدية')
            // النموذج 17's seventh check is derived from the evidence above,
            // never asked for — Appendix 70 refuses to let it be a claim.
            ->assertJsonPath('data.outputs.0.execution_checklist.execution_document_attached', 'yes')
            ->assertJsonPath('data.outputs.0.execution_checklist.employee_notified', 'yes');

        $executed = $requestRecord->fresh();
        $this->assertSame('executed', $executed->status->code);
        $this->assertSame('final_approval_archiving', $executed->currentStage->code);
        $this->assertSame($executor->id, $executed->executed_by_user_id);
        $this->assertNotNull($executed->executed_at);
        $this->assertSame('grade_amendment', $evidence->fresh()->execution_evidence_type);
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $requestRecord->id,
            'from_status_id' => RequestStatus::where('code', 'in_execution')->value('id'),
            'to_status_id' => RequestStatus::where('code', 'executed')->value('id'),
            'changed_by_user_id' => $executor->id,
        ]);
    }

    /** النموذج 17's four substantive card fields each bind on their own. */
    public function test_each_required_card_field_is_refused_when_blank(): void
    {
        [$executor, $meeting, $agendaItem, $requestRecord] = $this->executableOutput();

        foreach (['executing_body', 'action_taken', 'effective_date', 'approving_body'] as $field) {
            $this->actingAs($executor, 'sanctum')
                ->postJson($this->executeUrl($meeting, $agendaItem), $this->executionPayload($requestRecord, [$field => null]))
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->assertSame('in_execution', $requestRecord->fresh()->status->code);
    }

    /**
     * Tri-state, for Stage 75's reasons: a single "no" refuses and quotes the
     * failed question back, while "لا ينطبق" is a legitimate answer.
     */
    public function test_a_single_no_refuses_while_not_applicable_passes(): void
    {
        [$executor, $meeting, $agendaItem, $requestRecord] = $this->executableOutput();

        $response = $this->actingAs($executor, 'sanctum')
            ->postJson($this->executeUrl($meeting, $agendaItem), $this->executionPayload($requestRecord, checklistOverrides: [
                'organizational_unit_notified' => 'no',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->assertStringContainsString('هل تم إخطار التقسيم التنظيمي؟', $response->json('errors.action.0'));
        $this->assertNull($requestRecord->fresh()->executed_at);

        $this->actingAs($executor, 'sanctum')
            ->postJson($this->executeUrl($meeting, $agendaItem), $this->executionPayload($requestRecord, checklistOverrides: [
                'organizational_unit_notified' => 'not_applicable',
            ]))
            ->assertOk()
            ->assertJsonPath('data.outputs.0.execution_checklist.organizational_unit_notified', 'not_applicable');
    }

    /**
     * Art. 97 — the one متابعة التنفيذ answer with a system fact behind it.
     * A request Stage 47 flagged as carrying a financial effect cannot be
     * executed until that effect has actually been referred.
     */
    public function test_a_financial_effect_must_be_referred_before_execution_is_proven(): void
    {
        [$executor, $meeting, $agendaItem, $requestRecord] = $this->executableOutput();
        $requestRecord->update(['has_financial_impact' => true]);

        $this->actingAs($executor, 'sanctum')
            ->postJson($this->executeUrl($meeting, $agendaItem), $this->executionPayload($requestRecord, checklistOverrides: [
                'financial_effect_referred' => 'not_applicable',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->assertNull($requestRecord->fresh()->executed_at);

        $this->actingAs($executor, 'sanctum')
            ->postJson($this->executeUrl($meeting, $agendaItem), $this->executionPayload($requestRecord, checklistOverrides: [
                'financial_effect_referred' => 'yes',
            ]))
            ->assertOk()
            ->assertJsonPath('data.outputs.0.execution_status.code', 'executed');
    }

    /**
     * "لا ينطبق" stays honest on a request that carries no financial effect —
     * the binding above is not a blanket requirement.
     */
    public function test_an_unflagged_request_may_answer_the_financial_check_as_not_applicable(): void
    {
        [$executor, $meeting, $agendaItem, $requestRecord] = $this->executableOutput();

        $this->actingAs($executor, 'sanctum')
            ->postJson($this->executeUrl($meeting, $agendaItem), $this->executionPayload($requestRecord, checklistOverrides: [
                'financial_effect_referred' => 'not_applicable',
            ]))
            ->assertOk()
            ->assertJsonPath('data.outputs.0.execution_checklist.financial_effect_referred', 'not_applicable');
    }

    /** One-shot, like every other post-decision act since Stage 26. */
    public function test_execution_cannot_be_recorded_twice(): void
    {
        [$executor, $meeting, $agendaItem, $requestRecord] = $this->executableOutput();

        $this->actingAs($executor, 'sanctum')
            ->postJson($this->executeUrl($meeting, $agendaItem), $this->executionPayload($requestRecord))
            ->assertOk();

        $this->actingAs($executor, 'sanctum')
            ->postJson($this->executeUrl($meeting, $agendaItem), $this->executionPayload($requestRecord))
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');
    }

    /**
     * Stage 66's reopen must not leave the previous lap's proof standing, or
     * this stage's one-shot gate would refuse a second, genuine execution and a
     * superseded document would satisfy Appendix 70 for a lap it never covered.
     */
    public function test_reopening_clears_the_execution_record_and_unmarks_its_evidence(): void
    {
        [$executor, $meeting, $agendaItem, $requestRecord] = $this->executableOutput();
        $evidence = $this->executionEvidenceAttachment($requestRecord);

        $this->actingAs($executor, 'sanctum')
            ->postJson($this->executeUrl($meeting, $agendaItem), $this->executionPayload($requestRecord, [
                'evidence' => [['attachment_id' => $evidence->id, 'evidence_type' => 'administrative_decision']],
            ]))
            ->assertOk();

        $this->actingAs($executor, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertOk();

        // Stage 66 put reopening on the `appeals,edit` grant (R02/R08), not
        // on this screen's own — so the reopener is a different actor.
        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/reopen", [
                'reason_code' => 'material_error_correction',
                'target_stage_id' => WorkflowStage::where('code', 'reviewer_review')->value('id'),
            ])
            ->assertOk();

        $reopened = $requestRecord->fresh();
        $this->assertNull($reopened->executed_at);
        $this->assertNull($reopened->execution);
        $this->assertNull($reopened->execution_checklist);
        $this->assertNull($reopened->executed_by_user_id);
        // The document stays in the file; only its evidence designation goes.
        $this->assertNull($evidence->fresh()->execution_evidence_type);
        $this->assertDatabaseHas('attachments', ['id' => $evidence->id]);
    }

    /**
     * Stage 75's own open item (2): Appendix 47's ninth check stops being an
     * attestation and is read from the Appendix 70 evidence instead.
     */
    public function test_closure_derives_the_execution_document_check_from_real_evidence(): void
    {
        [$executor, $meeting, $agendaItem, $requestRecord] = $this->executableOutput();

        $this->actingAs($executor, 'sanctum')
            ->postJson($this->executeUrl($meeting, $agendaItem), $this->executionPayload($requestRecord))
            ->assertOk();

        // The closer never answers it — CloseRequest does not accept the key.
        $this->actingAs($executor, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload(auditOverrides: [
                'execution_document_attached' => 'no',
            ]))
            ->assertOk()
            ->assertJsonPath('data.closure_audit.execution_document_attached', 'yes');
    }

    /**
     * The fourth instance of the Stage 47/68/75 visibility gap: an executing
     * officer who did not create the request must be able to open it and attach
     * the very evidence Appendix 70 demands.
     */
    public function test_the_executor_can_open_and_attach_evidence_to_a_request_they_did_not_create(): void
    {
        Storage::fake('local');
        [$executor, , , $requestRecord] = $this->executableOutput();

        $this->assertNotSame($executor->id, $requestRecord->created_by_user_id);

        $this->actingAs($executor, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();

        $this->actingAs($executor, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('execution.pdf', 40, 'application/pdf'),
                // Stage 80 — Appendix 14's folder for a دليل التنفيذ document.
                'file_section' => 'execution',
            ])
            ->assertCreated();
    }

    /** The outputs screen's own two write tiers, unchanged by this stage. */
    public function test_only_the_outputs_edit_grant_may_prove_execution(): void
    {
        [, $meeting, $agendaItem, $requestRecord] = $this->executableOutput();
        $member = $this->userWithRole('R04');

        $this->actingAs($member, 'sanctum')
            ->postJson($this->executeUrl($meeting, $agendaItem), $this->executionPayload($requestRecord))
            ->assertForbidden();

        // Appendix 70 addresses execution follow-up to قسم شؤون الموظفين
        // (Art. 96), whose role here is R02.
        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->postJson($this->executeUrl($meeting, $agendaItem), $this->executionPayload($requestRecord))
            ->assertOk();
    }

    private function executeUrl(Meeting $meeting, MeetingRequest $agendaItem): string
    {
        return "/api/meetings/{$meeting->id}/outputs/{$agendaItem->id}/execute";
    }

    /** @return array{0: User, 1: Meeting, 2: MeetingRequest, 3: Request} */
    private function executableOutput(): array
    {
        $executor = $this->userWithRole('R03');
        $employee = $this->userWithRole('R01');

        $committee = Committee::create(['name_ar' => 'لجنة إثبات التنفيذ']);
        $committee->members()->create(['user_id' => $executor->id, 'is_head' => true]);
        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'meeting_number' => 'PM-MTG/2026/07',
            'title' => 'اجتماع متابعة التنفيذ',
            'scheduled_at' => now(),
            'created_by_user_id' => $executor->id,
        ]);

        $requestRecord = $this->requestRecord('final_approval_archiving', 'in_execution', $employee);
        $agendaItem = $meeting->agendaItems()->create([
            'request_id' => $requestRecord->id,
            'agenda_order' => 1,
            'item_state' => 'complete',
        ]);
        Decision::create([
            'meeting_request_id' => $agendaItem->id,
            'outcome' => 'approve',
            'votes_approve_count' => 2,
            'comment' => 'اعتمدت اللجنة الطلب.',
            'decided_by_user_id' => $executor->id,
            'decided_at' => now(),
        ]);

        return [$executor, $meeting, $agendaItem, $requestRecord];
    }

    private function requestRecord(string $stageCode, string $statusCode, User $employee): Request
    {
        return Request::create([
            'reference_number' => 'PM-COM/2026/'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'title' => 'طلب موظف لإثبات التنفيذ',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $employee->id,
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
