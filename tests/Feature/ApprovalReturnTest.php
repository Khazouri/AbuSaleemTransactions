<?php

namespace Tests\Feature;

use App\Models\ApprovalReturn;
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
use Tests\TestCase;

/**
 * Stage 77 — [D] Art. 94's إعادة المحضر من جهة الاعتماد and Appendix 34's
 * شكلية/موضوعية split, i.e. [A] §5's Path 3.
 */
class ApprovalReturnTest extends TestCase
{
    use ClosesRequests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Art. 94 records the return; it does not move the file. The re-processing
     * action is what does, and it has not been taken yet.
     */
    public function test_a_return_is_recorded_at_both_approval_checkpoints_without_moving_the_stage(): void
    {
        $recorder = $this->userWithRole('R02');

        foreach ([
            ['approval_by_authority', 'awaiting_municipal_approval'],
            ['local_governance_ministry', 'awaiting_central_approval'],
        ] as [$stage, $status]) {
            $requestRecord = $this->requestAt($stage, $status);

            $this->actingAs($recorder, 'sanctum')
                ->patchJson("/api/requests/{$requestRecord->id}/approval-return", $this->returnPayload())
                ->assertOk()
                ->assertJsonPath('data.status.code', 'returned_by_approving_body')
                ->assertJsonPath('data.current_stage.code', $stage)
                ->assertJsonPath('data.approval_returns.0.return_kind', 'formal')
                ->assertJsonPath('data.approval_returns.0.return_reason_code', 'missing_signature')
                ->assertJsonPath('data.approval_returns.0.returned_from_stage.code', $stage)
                ->assertJsonPath('data.approval_returns.0.recorded_by.id', $recorder->id)
                ->assertJsonPath('data.approval_returns.0.resolution_action', null)
                ->assertJsonPath('data.approval_return_eligibility.can_record', false);

            $fresh = $requestRecord->fresh();
            $this->assertSame($stage, $fresh->currentStage->code);
            $this->assertDatabaseHas('request_status_history', [
                'request_id' => $requestRecord->id,
                'from_status_id' => RequestStatus::where('code', $status)->value('id'),
                'to_status_id' => RequestStatus::where('code', 'returned_by_approving_body')->value('id'),
                'changed_by_user_id' => $recorder->id,
            ]);
            // Status-only: no stage log, because no stage moved.
            $this->assertDatabaseMissing('request_stage_logs', ['request_id' => $requestRecord->id]);
        }
    }

    /**
     * Appendix 34 classifies each reason itself, so a stated kind that
     * contradicts the appendix is refused — but `other` is free either way,
     * since both of its lists are introduced with "مثل".
     */
    public function test_a_reason_must_match_appendix_34s_own_classification_except_other(): void
    {
        $recorder = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return", $this->returnPayload([
                'return_kind' => 'substantive',
                'return_reason_code' => 'missing_signature',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'سبب الإعادة «توقيع ناقص» مصنف في الملحق 34 ضمن الإعادة الشكلية.');

        $this->assertSame(0, ApprovalReturn::count());

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return", $this->returnPayload([
                'return_kind' => 'substantive',
                'return_reason_code' => 'other',
            ]))
            ->assertOk()
            ->assertJsonPath('data.approval_returns.0.return_kind', 'substantive');
    }

    /** A file that is not with an approving body has nothing to be returned from. */
    public function test_a_return_is_refused_outside_the_approval_cycle_and_while_one_is_open(): void
    {
        $recorder = $this->userWithRole('R02');
        $early = $this->requestAt('reviewer_review', 'in_review');

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$early->id}/approval-return", $this->returnPayload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا تثبت الإعادة إلا لمعاملة محالة إلى جهة اعتماد.');

        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');
        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return", $this->returnPayload())
            ->assertOk();

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return", $this->returnPayload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'توجد إعادة من جهة الاعتماد لم يثبت بعد الإجراء المتخذ بشأنها.');

        $this->assertSame(1, ApprovalReturn::count());
    }

    /** Art. 94's second half is required, not optional. */
    public function test_the_return_card_and_the_resolution_both_require_their_own_mandatory_fields(): void
    {
        $recorder = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');

        foreach (['return_note', 'received_at'] as $field) {
            $this->actingAs($recorder, 'sanctum')
                ->patchJson("/api/requests/{$requestRecord->id}/approval-return", $this->returnPayload([$field => '']))
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return", $this->returnPayload())
            ->assertOk();

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return/resolve", ['resolution_action' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('resolution_action');
    }

    /**
     * Appendix 34's formal case: the مقرر corrects the defect himself and the
     * file is re-referred to the same body — no committee re-presentation, and
     * no stage move at all.
     */
    public function test_a_formal_resolution_re_refers_to_the_same_body(): void
    {
        $recorder = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('local_governance_ministry', 'awaiting_central_approval');

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return", $this->returnPayload([
                'return_reason_code' => 'numeric_error',
            ]))
            ->assertOk();

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return/resolve", [
                'resolution_action' => 'صحح الرقم في المحضر وأعيدت الإحالة إلى الوزارة.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'awaiting_central_approval')
            ->assertJsonPath('data.current_stage.code', 'local_governance_ministry')
            ->assertJsonPath('data.approval_returns.0.resolution_action', 'صحح الرقم في المحضر وأعيدت الإحالة إلى الوزارة.')
            ->assertJsonPath('data.approval_returns.0.resolved_by.id', $recorder->id)
            ->assertJsonPath('data.approval_returns.0.resolution_target_stage.code', 'local_governance_ministry')
            // The round is answered, so a fresh one may be recorded.
            ->assertJsonPath('data.approval_return_eligibility.can_record', true);

        $this->assertDatabaseMissing('request_stage_logs', ['request_id' => $requestRecord->id]);
    }

    /**
     * Appendix 34's substantive case: "لا يعدل المقرر القرار من تلقاء نفسه، بل
     * يعاد الموضوع إلى اللجنة" — and Art. 94's ban on quietly amending an
     * approved محضر means the previous decision survives untouched.
     */
    public function test_a_substantive_resolution_returns_the_matter_to_the_committee(): void
    {
        $recorder = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval', withDecision: true);
        $decisionId = Decision::query()->value('id');

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return", $this->returnPayload([
                'return_kind' => 'substantive',
                'return_reason_code' => 'legal_observation',
            ]))
            ->assertOk();

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return/resolve", [
                'resolution_action' => 'أعيد الموضوع إلى اللجنة لدراسة الملاحظة القانونية.',
            ])
            ->assertOk()
            // Art. 78's own إعادة عرض status, reused rather than invented.
            ->assertJsonPath('data.status.code', 'reopened_for_representation')
            ->assertJsonPath('data.current_stage.code', 'receive_from_committee')
            ->assertJsonPath('data.approval_returns.0.resolution_target_stage.code', 'receive_from_committee');

        // reopenAtStage() leaves the same audit trail an ordinary transition
        // would: a stage log AND a status-history row.
        $this->assertDatabaseHas('request_stage_logs', [
            'request_id' => $requestRecord->id,
            'action' => 'approval_return_restudy',
            'acted_by_user_id' => $recorder->id,
        ]);
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $requestRecord->id,
            'to_status_id' => RequestStatus::where('code', 'reopened_for_representation')->value('id'),
        ]);
        // Art. 94 — the approved record is not amended; a new decision is
        // recorded later instead.
        $this->assertDatabaseHas('decisions', ['id' => $decisionId, 'outcome' => 'approve']);
    }

    /**
     * Art. 94 — the approving body's remark is answered before the file is
     * approved onward. Both endpoints that reach the same `approve` must
     * refuse, and the detail screen must not offer the button either.
     */
    public function test_an_open_return_blocks_approval_through_both_entry_points(): void
    {
        Storage::fake('local');
        $recorder = $this->userWithRole('R02');
        $approver = $this->userWithRole('R05');
        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return", $this->returnPayload())
            ->assertOk();

        $message = 'لا يعتمد المحضر المعاد من جهة الاعتماد قبل إثبات إجراء إعادة المعالجة.';

        // The generic workspace endpoint.
        $this->actingAs($approver, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/transition", [
                'action' => 'approve',
                'signature' => $this->signatureFile(),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action')
            ->assertJsonPath('errors.action.0', $message);

        // The dedicated approval queue — the dual path Stage 54's own note
        // warns about; gating one alone would leave the other open.
        $this->actingAs($approver, 'sanctum')
            ->post("/api/approvals/admin-manager/{$requestRecord->id}", [
                'signature' => $this->signatureFile(),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.request.0', $message);

        // And the preview must agree with both.
        $this->actingAs($approver, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonMissing(['available_actions' => ['approve']]);

        $this->assertSame(0, $requestRecord->approvals()->count());
    }

    /** Appendix 48's seventh condition — the gap Stage 75 left open. */
    public function test_closure_is_refused_while_a_return_is_open(): void
    {
        $recorder = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return", $this->returnPayload())
            ->assertOk()
            ->assertJsonPath(
                'data.closure_eligibility.reason',
                'لا يجوز إقفال معاملة أعيدت من جهة الاعتماد.',
            );

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يجوز إقفال معاملة أعيدت من جهة الاعتماد.');
    }

    /** Art. 94's loop has no limit: a corrected file can come back again. */
    public function test_returns_accumulate_as_a_register_rather_than_overwriting(): void
    {
        $recorder = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return", $this->returnPayload())
            ->assertOk();
        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return/resolve", [
                'resolution_action' => 'استكمل التوقيع وأعيدت الإحالة.',
            ])
            ->assertOk();

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return", $this->returnPayload([
                'return_reason_code' => 'incomplete_data',
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'data.approval_returns')
            ->assertJsonPath('data.approval_returns.0.return_reason_code', 'missing_signature')
            ->assertJsonPath('data.approval_returns.1.return_reason_code', 'incomplete_data');
    }

    /**
     * Art. 30 addresses this register to مقرر اللجنة (R02), who holds no
     * workflow_transitions row at either approval checkpoint — so without the
     * RequestVisibility widening they would 404 on the very file they are
     * recording against.
     */
    public function test_the_recorder_can_open_a_file_they_did_not_create(): void
    {
        $recorder = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');

        $this->assertNotSame($recorder->id, $requestRecord->created_by_user_id);

        $this->actingAs($recorder, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.approval_return_eligibility.can_record', true);
    }

    /** Both actions ride `meeting_outputs,edit` — R02/R03 only. */
    public function test_a_role_without_the_grant_is_refused(): void
    {
        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');

        $this->actingAs($this->userWithRole('R04'), 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return", $this->returnPayload())
            ->assertForbidden();

        $this->actingAs($this->userWithRole('R03'), 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return", $this->returnPayload())
            ->assertOk();
    }

    /** Resolving before anything has been returned is a 422, not a 500. */
    public function test_resolving_with_no_open_return_is_refused(): void
    {
        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');

        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-return/resolve", [
                'resolution_action' => 'لا شيء',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا توجد إعادة من جهة الاعتماد بانتظار إثبات الإجراء المتخذ بشأنها.');
    }

    /**
     * A complete Art. 94 / Art. 30 return card.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function returnPayload(array $overrides = []): array
    {
        return [
            'return_kind' => 'formal',
            'return_reason_code' => 'missing_signature',
            'return_note' => 'المحضر غير موقع من أحد الأعضاء الحاضرين.',
            'letter_number' => 'ك/2026/77',
            'received_at' => now()->toDateString(),
            ...$overrides,
        ];
    }

    private function signatureFile(): UploadedFile
    {
        return UploadedFile::fake()->image('signature.png', 960, 330);
    }

    private function requestAt(string $stageCode, string $statusCode, bool $withDecision = false): Request
    {
        $creator = $this->userWithRole('R01');
        $department = Department::query()->where('code', 'ADM')->firstOrFail();
        $type = RequestType::query()->firstOrFail();

        $requestRecord = Request::create([
            'reference_number' => 'PM-COM/2026/'.str_pad((string) (Request::count() + 1), 4, '0', STR_PAD_LEFT),
            'title' => 'معاملة لدى جهة الاعتماد',
            'department_id' => $department->id,
            'request_type_id' => $type->id,
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now()->subMonth(),
        ]);

        if ($withDecision) {
            $committee = Committee::create(['name_ar' => 'لجنة الاعتماد']);
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
