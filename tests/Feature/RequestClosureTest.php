<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AppealStatus;
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
use Tests\ClosesRequests;
use Tests\TestCase;

/**
 * Stage 75 — [D] Art. 37's الإقفال: its four final paths, Appendix 47's
 * twelve-point pre-closure audit and Appendix 48's refusal conditions.
 */
class RequestClosureTest extends TestCase
{
    use ClosesRequests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Art. 37's first final path — اعتماد النتيجة + تنفيذها + تحديث الملف
     * الوظيفي + استكمال الإشعارات.
     */
    public function test_an_executed_request_closes_and_records_art_37s_eight_fields(): void
    {
        $closer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('final_approval_archiving', 'executed', withDecision: true);

        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertOk()
            ->assertJsonPath('data.status.code', 'completed_closed')
            // Computed, never client-supplied.
            ->assertJsonPath('data.closure.final_result_code', 'executed')
            ->assertJsonPath('data.closure.notice_status', 'notified')
            ->assertJsonPath('data.closure.approving_body', 'عميد البلدية')
            ->assertJsonPath('data.closure.executing_body', 'إدارة الموارد البشرية')
            ->assertJsonPath('data.closure.final_decision_number', 'PM-DEC/2026/001')
            ->assertJsonPath('data.closure.file_storage_location', 'أرشيف قسم شؤون الموظفين — خزانة 3')
            ->assertJsonPath('data.closure.closed_by.id', $closer->id)
            ->assertJsonPath('data.closure_eligibility.can_close', false)
            // All twelve of Appendix 47's checks are stored, including the two
            // the server answers itself.
            ->assertJsonPath('data.closure_audit.appeal_path_concluded', 'yes')
            ->assertJsonPath('data.closure_audit.archive_location_set', 'yes')
            ->assertJsonPath('data.closure_audit.service_file_updated', 'yes');

        $closed = $requestRecord->fresh();
        $this->assertNotNull($closed->closed_at);
        $this->assertSame('completed_closed', $closed->status->code);
        // Status-only: WorkflowService stays the sole owner of stage movement.
        $this->assertSame('final_approval_archiving', $closed->currentStage->code);
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $requestRecord->id,
            'from_status_id' => RequestStatus::where('code', 'executed')->value('id'),
            'to_status_id' => RequestStatus::where('code', 'completed_closed')->value('id'),
            'changed_by_user_id' => $closer->id,
        ]);
    }

    /**
     * Art. 37's second and third final paths — the gap Stage 69's own note left
     * open here ("Stage 75 should decide whether closure applies to refused
     * requests too"). Art. 37 answers it explicitly: it does.
     */
    public function test_a_refused_request_and_a_no_jurisdiction_request_both_close(): void
    {
        $closer = $this->userWithRole('R02');

        $refused = $this->requestAt('receive_from_committee', 'not_approved', withDecision: true);
        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$refused->id}/close", $this->closurePayload())
            ->assertOk()
            ->assertJsonPath('data.status.code', 'completed_closed')
            ->assertJsonPath('data.closure.final_result_code', 'not_approved');

        // A Stage 54 pre-committee عدم اختصاص never reached an agenda at all,
        // so it carries no decision, no محضر and nothing to execute — which is
        // exactly why the audit is tri-state rather than a set of booleans.
        $outsideJurisdiction = $this->requestAt('requirements_check', 'outside_jurisdiction');
        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$outsideJurisdiction->id}/close", $this->closurePayload(auditOverrides: [
                'minutes_approved' => 'not_applicable',
                'authority_approval_complete' => 'not_applicable',
                'executed' => 'not_applicable',
                'service_file_updated' => 'not_applicable',
                'decision_copy_attached' => 'not_applicable',
                // `execution_document_attached` is deliberately absent: Stage 76
                // made it server-derived from Appendix 70 evidence, so it is no
                // longer a question the closer answers. The assertion below
                // proves the derivation reports it for this unexecuted path.
            ]))
            ->assertOk()
            ->assertJsonPath('data.status.code', 'completed_closed')
            ->assertJsonPath('data.closure.final_result_code', 'outside_jurisdiction')
            ->assertJsonPath('data.closure_audit.minutes_approved', 'not_applicable')
            // Stage 76 — Art. 37's Path 3 closes a request that was never
            // executed, so there honestly is no دليل التنفيذ to have attached.
            ->assertJsonPath('data.closure_audit.execution_document_attached', 'not_applicable');
    }

    /**
     * Appendix 48's conditions 1–5, each refused in its own words rather than
     * behind one generic "not closable" message.
     */
    public function test_appendix_48_refuses_each_pending_state_by_name(): void
    {
        $closer = $this->userWithRole('R02');

        $cases = [
            'awaiting_municipal_approval' => 'بانتظار الاعتماد',
            'awaiting_central_approval' => 'بانتظار رد الوزارة',
            'in_execution' => 'تحت التنفيذ',
            'deferred' => 'مؤجلة',
            'completion_required' => 'بانتظار مستند طلبته اللجنة',
        ];

        foreach ($cases as $statusCode => $fragment) {
            $requestRecord = $this->requestAt('receive_from_committee', $statusCode);

            $response = $this->actingAs($closer, 'sanctum')
                ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
                ->assertStatus(422);

            $this->assertStringContainsString(
                $fragment,
                $response->json('message'),
                "Appendix 48 should refuse a {$statusCode} request in its own words.",
            );
            $this->assertNull($requestRecord->fresh()->closed_at);
        }

        // Anything else that simply hasn't reached a final result falls through
        // to Art. 37's own sentence about the four paths.
        $inReview = $this->requestAt('reviewer_review', 'in_review');
        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$inReview->id}/close", $this->closurePayload())
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'لا تعتبر المعاملة مقفلة إلا بعد تحقق أحد المسارات النهائية: تنفيذ النتيجة، أو عدم الموافقة، أو عدم الاختصاص.']);
    }

    /** Appendix 48 condition 6 / Art. 37's fourth path. */
    public function test_an_open_appeal_holds_the_file_open_and_closing_it_releases_the_hold(): void
    {
        $closer = $this->userWithRole('R02');
        $employee = $this->userWithRole('R01');
        $requestRecord = $this->requestAt('final_approval_archiving', 'executed', withDecision: true, creator: $employee);

        $appeal = Appeal::create([
            'appellant_user_id' => $employee->id,
            'original_request_id' => $requestRecord->id,
            'original_decision_reference' => 'قرار تنفيذ',
            'known_at' => now()->subDay(),
            'appeal_reasons' => 'اعتراض على أسلوب التنفيذ.',
            'final_request' => 'مراجعة القرار.',
            'appeal_status_id' => AppealStatus::where('code', 'submitted')->value('id'),
        ]);

        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'لا يجوز إقفال معاملة مرتبطة بتظلم مفتوح.']);

        $appeal->update(['appeal_status_id' => AppealStatus::where('code', 'notified_closed')->value('id')]);

        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertOk()
            ->assertJsonPath('data.status.code', 'completed_closed');
    }

    /**
     * Appendix 47's own rule ("إلا بعد الإجابة بنعم على الآتي") — a single "لا"
     * refuses, while "لا ينطبق" passes for a check the path genuinely does not
     * reach.
     */
    public function test_a_single_no_answer_refuses_the_closure(): void
    {
        $closer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('final_approval_archiving', 'executed', withDecision: true);

        $response = $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload(auditOverrides: [
                'employee_notified' => 'no',
            ]))
            ->assertStatus(422);

        $this->assertStringContainsString('هل تم إشعار الموظف؟', $response->json('message'));
        $this->assertNull($requestRecord->fresh()->closed_at);

        // An unanswered check is refused by validation, not silently treated
        // as a pass — the same reasoning Stage 54 used for Art. 45's six
        // questions.
        $payload = $this->closurePayload();
        unset($payload['audit']['no_party_awaiting_action']);

        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('audit.no_party_awaiting_action');
    }

    /**
     * Appendix 48's eighth condition — "صدر قرارها ولم يتم تحديث ملف الموظف".
     * The only one enforced against the audit's own answer, because Track K's
     * scope decision (1) puts ملف الخدمة outside this application.
     */
    public function test_a_decided_request_cannot_be_closed_with_the_service_file_left_unupdated(): void
    {
        $closer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('final_approval_archiving', 'executed', withDecision: true);

        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload(auditOverrides: [
                'service_file_updated' => 'not_applicable',
            ]))
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'لا يجوز إقفال معاملة صدر قرارها ولم يتم تحديث ملف الموظف.']);

        $this->assertNull($requestRecord->fresh()->closed_at);
    }

    /** Art. 37's card: جهة الاعتماد and موقع حفظ الملف are not optional. */
    public function test_the_closure_card_requires_the_approving_body_and_the_archive_location(): void
    {
        $closer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('final_approval_archiving', 'executed', withDecision: true);

        $payload = $this->closurePayload();
        $payload['approving_body'] = '';
        $payload['file_storage_location'] = '';

        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['approving_body', 'file_storage_location']);
    }

    /** النموذج 18's "لا تقفل المعاملة قبل استكمال البطاقة" is one-shot. */
    public function test_a_closed_request_cannot_be_closed_twice_but_a_reopen_clears_the_card(): void
    {
        $closer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('final_approval_archiving', 'executed', withDecision: true);

        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertOk();

        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'المعاملة مقفلة بالفعل.']);

        // Stage 66's re-presentation must not leave the previous lap's card
        // attached, or this one-shot gate would stay stuck.
        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/reopen", [
                'reason_code' => 'new_document',
                'target_stage_id' => WorkflowStage::where('code', 'reviewer_review')->value('id'),
            ])
            ->assertOk()
            ->assertJsonPath('data.closure', null)
            ->assertJsonPath('data.closure_audit', null);

        $reopened = $requestRecord->fresh();
        $this->assertNull($reopened->closed_at);
        $this->assertNull($reopened->closed_by_user_id);
    }

    /**
     * Stage 65's own reasoning, mirrored: an inactive requester cannot be
     * reached, and that is a fact about the account rather than something the
     * closer attests to.
     */
    public function test_the_notice_status_is_computed_from_the_requesters_own_account(): void
    {
        $closer = $this->userWithRole('R02');
        $employee = $this->userWithRole('R01');
        $employee->update(['is_active' => false]);

        $requestRecord = $this->requestAt('final_approval_archiving', 'executed', withDecision: true, creator: $employee);

        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload([
                // Deliberately spoofed: neither is accepted from the client.
                'final_result_code' => 'archived',
                'notice_status' => 'notified',
            ]))
            ->assertOk()
            ->assertJsonPath('data.closure.notice_status', 'requester_unreachable')
            ->assertJsonPath('data.closure.final_result_code', 'executed');
    }

    /**
     * Appendix 47 addresses closure to المقرر; Stage 92 split this off
     * `meeting_outputs,edit` onto its own `approve` tier ([F] step 10's "who
     * executed" question), so the grant is now R02 + R03 + R12 (+ R08).
     */
    public function test_closing_requires_the_meeting_outputs_approve_grant(): void
    {
        $requestRecord = $this->requestAt('final_approval_archiving', 'executed', withDecision: true);

        $this->actingAs($this->userWithRole('R04'), 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertForbidden();

        $this->actingAs($this->userWithRole('R03'), 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertOk();
    }

    /**
     * The party Stage 92 actually adds: HR can close a file it did not create
     * — recording that HR itself was the executing body, not R02/R03 on HR's
     * behalf — and can open that file beforehand, the same bounded visibility
     * R02/R03 already have via `$isCloser`. A role with no reach still 404s.
     */
    public function test_hr_can_close_a_request_it_did_not_create(): void
    {
        $hrExecutingBody = 'إدارة الموارد البشرية';
        $requestRecord = $this->requestAt('final_approval_archiving', 'executed', withDecision: true);
        $hr = $this->userWithRole('R12');

        $this->actingAs($hr, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload([
                'executing_body' => $hrExecutingBody,
            ]))
            ->assertOk()
            ->assertJsonPath('data.closure.executing_body', $hrExecutingBody);

        $unrelatedRequest = $this->requestAt('final_approval_archiving', 'executed', withDecision: true);

        $this->actingAs($this->userWithRole('R04'), 'sanctum')
            ->getJson("/api/requests/{$unrelatedRequest->id}")
            ->assertNotFound();
    }

    /**
     * The detail screen's "why not" is the endpoint's own refusal, computed
     * once by the same service — and the closer can actually open the file
     * they are meant to close, which RequestVisibility's terminal-status rule
     * would otherwise refuse them.
     */
    public function test_the_detail_screen_reports_the_same_refusal_the_endpoint_would_raise(): void
    {
        $employee = $this->userWithRole('R01');
        $deferred = $this->requestAt('receive_from_committee', 'deferred', creator: $employee);

        // Fetched as the creator: a مؤجلة request is not closable, so the
        // Stage 75 visibility clause deliberately does not reach it.
        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$deferred->id}")
            ->assertOk()
            ->assertJsonPath('data.closure_eligibility.can_close', false)
            ->assertJsonPath('data.closure_eligibility.reason', 'لا يجوز إقفال معاملة مؤجلة.')
            ->assertJsonPath('data.closure', null);

        $closer = $this->userWithRole('R02');
        $executed = $this->requestAt('final_approval_archiving', 'executed', withDecision: true);

        $this->actingAs($closer, 'sanctum')
            ->getJson("/api/requests/{$executed->id}")
            ->assertOk()
            ->assertJsonPath('data.closure_eligibility.can_close', true)
            ->assertJsonPath('data.closure_eligibility.reason', null);
    }

    private function requestAt(
        string $stageCode,
        string $statusCode,
        bool $withDecision = false,
        ?User $creator = null,
    ): Request {
        $creator ??= $this->userWithRole('R01');
        $department = Department::query()->where('code', 'ADM')->firstOrFail();
        $type = RequestType::query()->firstOrFail();

        $requestRecord = Request::create([
            'reference_number' => 'PM-COM/2026/'.str_pad((string) (Request::count() + 1), 4, '0', STR_PAD_LEFT),
            'title' => 'معاملة قيد الإقفال',
            'department_id' => $department->id,
            'request_type_id' => $type->id,
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now()->subMonth(),
        ]);

        if ($withDecision) {
            $committee = Committee::create(['name_ar' => 'لجنة الإقفال']);
            $meeting = Meeting::create([
                'committee_id' => $committee->id,
                'title' => 'اجتماع',
                'scheduled_at' => now()->subWeek(),
                'created_by_user_id' => $creator->id,
            ]);
            $agendaItem = MeetingRequest::create([
                'meeting_id' => $meeting->id,
                'request_id' => $requestRecord->id,
                'agenda_order' => 1,
            ]);
            Decision::create([
                'meeting_request_id' => $agendaItem->id,
                'outcome' => 'approve',
                'decided_by_user_id' => $this->userWithRole('R03')->id,
                'decided_at' => now()->subWeek(),
            ]);
        }

        return $requestRecord->fresh();
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'department_id' => Department::query()->where('code', 'ADM')->value('id'),
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));

        return $user;
    }
}
