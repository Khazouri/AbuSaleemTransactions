<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AppealAttachment;
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
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stage 59, Track J — real appeal intake: ownership, the decided-status
 * restriction (Art. 38 codes 12/13/14/17/19/20 via Stage 54b's
 * reconciliation table), non-duplication, auto-filled decision targeting,
 * supporting-document upload/preview, and the Track J intro's scope
 * decision (3) half this stage owns (the block, not the release).
 *
 * Stage 60 — the formal-verification gate (AppealController::verify()):
 * pass/fail outcomes, the one-shot guard, the self-verification block, the
 * `appeals,edit` visibility widening, and the configurable filing deadline.
 */
class AppealTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_an_appeal_round_trips_through_create_and_list(): void
    {
        $employee = $this->userWithRole('R01');
        $target = $this->requestFixture($employee, 'final_approved');

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', $this->payload($target->id, [
                'original_decision_reference' => 'قرار اعتماد نهائي رقم 4',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.appellant.id', $employee->id)
            ->assertJsonPath('data.original_request.id', $target->id)
            ->assertJsonPath('data.status.code', 'submitted')
            ->assertJsonPath('data.known_at', now()->subDay()->toDateString())
            ->assertJsonPath('data.appeal_reasons', 'القرار خالف الإجراءات المتبعة.')
            ->assertJsonPath('data.final_request', 'إعادة النظر في القرار.')
            ->assertJsonPath('data.attachments_count', 0);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/appeals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.original_request.reference_number', $target->reference_number);
    }

    public function test_the_appellant_is_always_the_acting_user_regardless_of_client_input(): void
    {
        $employee = $this->userWithRole('R01');
        $someoneElse = $this->userWithRole('R01');
        $target = $this->requestFixture($employee, 'final_approved');

        // appellant_user_id isn't even a validated field — passing it must
        // have zero effect on who the row records as the filer.
        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', $this->payload($target->id, [
                'original_decision_reference' => 'قرار اعتماد نهائي رقم 5',
                'appellant_user_id' => $someoneElse->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.appellant.id', $employee->id);

        $this->assertDatabaseHas('appeals', [
            'original_request_id' => $target->id,
            'appellant_user_id' => $employee->id,
        ]);
    }

    public function test_a_client_supplied_decision_id_is_ignored_the_server_derives_it(): void
    {
        $employee = $this->userWithRole('R01');
        [$target, $decision] = $this->decidedRequestFixture($employee, 'decided', 'approve');

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', $this->payload($target->id, ['original_decision_id' => 999999]))
            ->assertCreated()
            ->assertJsonPath('data.original_decision_id', $decision->id);
    }

    public function test_a_non_admin_actor_sees_only_their_own_appeals_while_r08_sees_all(): void
    {
        $first = $this->userWithRole('R01');
        $second = $this->userWithRole('R01');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();

        $this->actingAs($first, 'sanctum')
            ->postJson('/api/appeals', $this->payload(
                $this->requestFixture($first, 'final_approved')->id,
                ['original_decision_reference' => 'قرار 1'],
            ))
            ->assertCreated();
        $this->actingAs($second, 'sanctum')
            ->postJson('/api/appeals', $this->payload(
                $this->requestFixture($second, 'final_approved')->id,
                ['original_decision_reference' => 'قرار 2'],
            ))
            ->assertCreated();

        $this->actingAs($first, 'sanctum')
            ->getJson('/api/appeals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.appellant.id', $first->id);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/appeals')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_role_without_appeals_add_permission_is_refused(): void
    {
        $reviewer = $this->userWithRole('R02');
        $target = $this->requestFixture($reviewer, 'final_approved');

        $this->actingAs($reviewer, 'sanctum')
            ->postJson('/api/appeals', $this->payload($target->id))
            ->assertForbidden();

        // `view` is broad, unlike `add` — R02 can still see the (empty) list.
        $this->actingAs($reviewer, 'sanctum')
            ->getJson('/api/appeals')
            ->assertOk();
    }

    public function test_ownership_is_enforced_an_appeal_may_not_target_someone_elses_request(): void
    {
        $owner = $this->userWithRole('R01');
        $stranger = $this->userWithRole('R01');
        $target = $this->requestFixture($owner, 'final_approved');

        $this->actingAs($stranger, 'sanctum')
            ->postJson('/api/appeals', $this->payload($target->id, [
                'original_decision_reference' => 'قرار غير مملوك للمتظلم',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('original_request_id');

        $this->assertDatabaseCount('appeals', 0);
    }

    public function test_only_a_request_that_reached_a_result_is_appealable(): void
    {
        $employee = $this->userWithRole('R01');

        // Mid-pipeline — no result reached yet.
        $inReview = $this->requestFixture($employee, 'in_review');
        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', $this->payload($inReview->id, [
                'original_decision_reference' => 'لا يوجد',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('original_request_id');

        // Deliberately excluded even though it's late-pipeline: Art. 38 code
        // 18 (تحت التنفيذ) sits between 17 and 19/20, both of which qualify,
        // but STAGE_PLAN's own code list skips 18.
        $inExecution = $this->requestFixture($employee, 'in_execution');
        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', $this->payload($inExecution->id, [
                'original_decision_reference' => 'لا يوجد',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('original_request_id');

        // Every qualifying code actually works.
        foreach (['decided', 'approved_with_conditions', 'outside_jurisdiction', 'final_approved', 'completed_closed', 'archived'] as $statusCode) {
            $qualifying = $this->requestFixture($employee, $statusCode);

            $this->actingAs($employee, 'sanctum')
                ->postJson('/api/appeals', $this->payload($qualifying->id, [
                    'original_decision_reference' => "قرار بشأن {$statusCode}",
                ]))
                ->assertCreated();
        }
    }

    public function test_cancelled_only_qualifies_when_a_linked_committee_rejection_decision_exists(): void
    {
        $employee = $this->userWithRole('R01');

        // A plain administrative withdrawal — cancelled with no Decision at all.
        $withdrawn = $this->requestFixture($employee, 'cancelled');
        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', $this->payload($withdrawn->id, [
                'original_decision_reference' => 'لا يوجد',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('original_request_id');

        // A genuine committee rejection — cancelled status, driven by a real
        // Decision with outcome=reject (DecisionController's outcome→action
        // map routes `reject` through the same self-loop as a plain cancel).
        [$rejectedByCommittee, $decision] = $this->decidedRequestFixture($employee, 'cancelled', 'reject');
        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', $this->payload($rejectedByCommittee->id))
            ->assertCreated()
            ->assertJsonPath('data.original_decision_id', $decision->id);
    }

    public function test_a_second_appeal_requires_a_new_facts_declaration(): void
    {
        $employee = $this->userWithRole('R01');
        $target = $this->requestFixture($employee, 'final_approved');

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', $this->payload($target->id, [
                'original_decision_reference' => 'قرار أول',
            ]))
            ->assertCreated();

        // Same request, same appellant, no new facts declared.
        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', $this->payload($target->id, [
                'original_decision_reference' => 'قرار أول',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('original_request_id');

        $this->assertDatabaseCount('appeals', 1);

        // New facts declared — allowed.
        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', $this->payload($target->id, [
                'original_decision_reference' => 'قرار أول',
                'new_facts_declaration' => 'ظهرت مستندات جديدة لم تكن متاحة عند التظلم الأول.',
            ]))
            ->assertCreated();

        $this->assertDatabaseCount('appeals', 2);
    }

    public function test_an_appeal_targets_a_recorded_decision_or_falls_back_to_a_free_text_reference(): void
    {
        $employee = $this->userWithRole('R01');

        // With a real Decision row — original_decision_id is auto-filled,
        // no client-supplied reference needed.
        [$decidedRequest, $decision] = $this->decidedRequestFixture($employee, 'decided', 'approve');

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', $this->payload($decidedRequest->id))
            ->assertCreated()
            ->assertJsonPath('data.original_decision_id', $decision->id);

        // Without one — outside_jurisdiction reached pre-committee at
        // requirements_check (Stage 54's declare_no_jurisdiction) never
        // creates a Decision row, so the free-text fallback is required.
        $undecidedRequest = $this->requestFixture($employee, 'outside_jurisdiction');

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', $this->payload($undecidedRequest->id, [
                'original_decision_reference' => 'قرار رفض شكلي رقم 12',
                'original_decision_date' => '2026-08-01',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.original_decision_id', null)
            ->assertJsonPath('data.original_decision_reference', 'قرار رفض شكلي رقم 12');
    }

    public function test_a_decision_less_target_without_a_reference_is_rejected(): void
    {
        $employee = $this->userWithRole('R01');
        $undecidedRequest = $this->requestFixture($employee, 'outside_jurisdiction');

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', $this->payload($undecidedRequest->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('original_decision_reference');

        $this->assertDatabaseCount('appeals', 0);
    }

    public function test_a_nonexistent_target_is_rejected(): void
    {
        $employee = $this->userWithRole('R01');

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', $this->payload(999999))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('original_request_id');

        $this->assertDatabaseCount('appeals', 0);
    }

    public function test_a_supporting_document_can_be_uploaded_and_previewed_by_the_appellant(): void
    {
        Storage::fake('local');
        $employee = $this->userWithRole('R01');
        $appeal = $this->appealFixture($employee);

        $upload = $this->actingAs($employee, 'sanctum')
            ->post("/api/appeals/{$appeal->id}/attachments", [
                'file' => UploadedFile::fake()->create('evidence.pdf', 50, 'application/pdf'),
                'label' => 'مستند داعم',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.appeal_id', $appeal->id)
            ->assertJsonPath('data.original_name', 'evidence.pdf')
            ->assertJsonPath('data.label', 'مستند داعم');

        $attachment = AppealAttachment::firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame($upload->json('data.id'), $attachment->id);

        $this->actingAs($employee, 'sanctum')
            ->get(route('appeals.attachments.preview', ['appeal' => $appeal, 'attachment' => $attachment]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_attachment_access_is_scoped_to_the_appellant_or_admin_and_the_owning_appeal(): void
    {
        Storage::fake('local');
        $employee = $this->userWithRole('R01');
        $stranger = $this->userWithRole('R01');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $appeal = $this->appealFixture($employee);
        $otherAppeal = $this->appealFixture($stranger);

        $attachment = AppealAttachment::create([
            'appeal_id' => $appeal->id,
            'disk' => 'local',
            'path' => "appeal-attachments/{$appeal->id}/evidence.pdf",
            'original_name' => 'evidence.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
        ]);
        Storage::disk('local')->put($attachment->path, 'pdf-bytes');

        // A stranger cannot preview someone else's appeal document.
        $this->actingAs($stranger, 'sanctum')
            ->get(route('appeals.attachments.preview', ['appeal' => $appeal, 'attachment' => $attachment]))
            ->assertNotFound();

        // A stranger cannot upload to someone else's appeal either.
        $this->actingAs($stranger, 'sanctum')
            ->post("/api/appeals/{$appeal->id}/attachments", [
                'file' => UploadedFile::fake()->create('sneaky.pdf', 10, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertNotFound();

        // The attachment must actually belong to the appeal in the URL.
        $this->actingAs($employee, 'sanctum')
            ->get(route('appeals.attachments.preview', ['appeal' => $otherAppeal, 'attachment' => $attachment]))
            ->assertNotFound();

        // The owner and R08 can both preview it.
        $this->actingAs($employee, 'sanctum')
            ->get(route('appeals.attachments.preview', ['appeal' => $appeal, 'attachment' => $attachment]))
            ->assertOk();
        $this->actingAs($admin, 'sanctum')
            ->get(route('appeals.attachments.preview', ['appeal' => $appeal, 'attachment' => $attachment]))
            ->assertOk();
    }

    public function test_a_passing_verification_advances_the_appeal_and_records_who_when_why(): void
    {
        $employee = $this->userWithRole('R01');
        $reviewer = $this->userWithRole('R02');
        $appeal = $this->appealFixture($employee);

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/appeals/{$appeal->id}/verify", [
                'appellant_standing' => true,
                'valid_target_decision' => true,
                'non_duplication' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'formal_verification')
            ->assertJsonPath('data.formal_verification.checks.appellant_standing', true)
            ->assertJsonPath('data.formal_verification.checks.valid_target_decision', true)
            ->assertJsonPath('data.formal_verification.checks.non_duplication', true)
            ->assertJsonPath('data.formal_verification.checks.deadline_met', null)
            ->assertJsonPath('data.formal_verification.reason', null)
            ->assertJsonPath('data.formal_verification.verified_by.id', $reviewer->id);

        $this->assertDatabaseHas('appeals', [
            'id' => $appeal->id,
            'formal_verified_by_user_id' => $reviewer->id,
        ]);
        $this->assertNotNull($appeal->fresh()->formal_verified_at);
    }

    public function test_a_failing_verification_requires_a_reason_and_closes_the_appeal_as_rejected(): void
    {
        $employee = $this->userWithRole('R01');
        $reviewer = $this->userWithRole('R02');
        $appeal = $this->appealFixture($employee);

        // No reason supplied — refused, nothing recorded.
        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/appeals/{$appeal->id}/verify", [
                'appellant_standing' => false,
                'valid_target_decision' => true,
                'non_duplication' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertNull($appeal->fresh()->formal_verified_at);

        // With a reason — closes as rejected.
        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/appeals/{$appeal->id}/verify", [
                'appellant_standing' => false,
                'valid_target_decision' => true,
                'non_duplication' => true,
                'reason' => 'المتظلم ليس صاحب الطلب موضوع التظلم.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'rejected')
            ->assertJsonPath('data.formal_verification.checks.appellant_standing', false)
            ->assertJsonPath('data.formal_verification.reason', 'المتظلم ليس صاحب الطلب موضوع التظلم.');
    }

    public function test_an_already_verified_appeal_cannot_be_verified_again(): void
    {
        $employee = $this->userWithRole('R01');
        $reviewer = $this->userWithRole('R02');
        $appeal = $this->appealFixture($employee);
        $appeal->update(['appeal_status_id' => AppealStatus::where('code', 'formal_verification')->value('id')]);

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/appeals/{$appeal->id}/verify", [
                'appellant_standing' => true,
                'valid_target_decision' => true,
                'non_duplication' => true,
            ])
            ->assertUnprocessable();
    }

    public function test_the_appellant_cannot_verify_their_own_appeal(): void
    {
        $employee = $this->userWithRole('R01');
        $employee->roles()->attach(Role::where('code', 'R02')->value('id'));
        $appeal = $this->appealFixture($employee);

        $this->actingAs($employee, 'sanctum')
            ->postJson("/api/appeals/{$appeal->id}/verify", [
                'appellant_standing' => true,
                'valid_target_decision' => true,
                'non_duplication' => true,
            ])
            ->assertUnprocessable();
    }

    public function test_a_role_without_appeals_edit_permission_is_refused(): void
    {
        $employee = $this->userWithRole('R01');
        $anotherEmployee = $this->userWithRole('R01');
        $appeal = $this->appealFixture($employee);

        $this->actingAs($anotherEmployee, 'sanctum')
            ->postJson("/api/appeals/{$appeal->id}/verify", [
                'appellant_standing' => true,
                'valid_target_decision' => true,
                'non_duplication' => true,
            ])
            ->assertForbidden();
    }

    public function test_the_filing_deadline_check_is_skipped_until_configured_then_enforced(): void
    {
        $employee = $this->userWithRole('R01');
        $reviewer = $this->userWithRole('R02');

        // No Setting row configured yet (SettingSeeder seeds it empty) —
        // deadline_met is null and never blocks the verdict.
        $onTimeAppeal = $this->appealFixture($employee);
        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/appeals/{$onTimeAppeal->id}/verify", [
                'appellant_standing' => true,
                'valid_target_decision' => true,
                'non_duplication' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'formal_verification')
            ->assertJsonPath('data.formal_verification.checks.deadline_met', null);

        // Configure a 5-day deadline and file well past it.
        Setting::where('key', 'appeal_filing_deadline_days')->update(['value' => '5']);
        $lateAppeal = $this->appealFixture($employee);
        $lateAppeal->forceFill(['known_at' => now()->subDays(30)])->save();

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/appeals/{$lateAppeal->id}/verify", [
                'appellant_standing' => true,
                'valid_target_decision' => true,
                'non_duplication' => true,
                'reason' => 'تجاوز المدة القانونية لتقديم التظلم.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'rejected')
            ->assertJsonPath('data.formal_verification.checks.deadline_met', false);
    }

    public function test_r02_sees_appeals_filed_by_others_once_granted_edit_while_r01_still_sees_only_their_own(): void
    {
        $filer = $this->userWithRole('R01');
        $reviewer = $this->userWithRole('R02');
        $this->appealFixture($filer);

        $this->actingAs($reviewer, 'sanctum')
            ->getJson('/api/appeals')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($filer, 'sanctum')
            ->getJson('/api/appeals?status=submitted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.appellant.id', $filer->id);
    }

    // --- helpers ------------------------------------------------------------

    private function payload(int $requestId, array $overrides = []): array
    {
        return array_merge([
            'original_request_id' => $requestId,
            'known_at' => now()->subDay()->toDateString(),
            'appeal_reasons' => 'القرار خالف الإجراءات المتبعة.',
            'final_request' => 'إعادة النظر في القرار.',
        ], $overrides);
    }

    private function requestFixture(User $creator, string $statusCode): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب صدر بشأنه قرار',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'final_approval_archiving')->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now(),
        ]);
    }

    /** @return array{0: Request, 1: Decision} */
    private function decidedRequestFixture(User $creator, string $statusCode, string $outcome): array
    {
        $target = $this->requestFixture($creator, $statusCode);
        $committee = Committee::create(['name_ar' => 'لجنة اختبار التظلمات']);
        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع صدر عنه قرار',
            'status' => 'completed',
            'scheduled_at' => now()->subWeek(),
            'created_by_user_id' => $creator->id,
        ]);
        $agendaItem = MeetingRequest::create([
            'meeting_id' => $meeting->id,
            'request_id' => $target->id,
            'agenda_order' => 1,
            'item_type' => 'employee_request',
        ]);
        $decision = Decision::create([
            'meeting_request_id' => $agendaItem->id,
            'outcome' => $outcome,
            'votes_approve_count' => $outcome === 'approve' ? 2 : 0,
            'votes_reject_count' => $outcome === 'reject' ? 2 : 0,
            'votes_defer_count' => 0,
            'decided_by_user_id' => $creator->id,
            'decided_at' => now(),
        ]);

        return [$target, $decision];
    }

    private function appealFixture(User $appellant): Appeal
    {
        $target = $this->requestFixture($appellant, 'final_approved');

        return Appeal::create([
            'appellant_user_id' => $appellant->id,
            'original_request_id' => $target->id,
            'original_decision_reference' => 'قرار اعتماد نهائي',
            'known_at' => now()->subDay(),
            'appeal_reasons' => 'القرار خالف الإجراءات المتبعة.',
            'final_request' => 'إعادة النظر في القرار.',
            'appeal_status_id' => AppealStatus::where('code', 'submitted')->value('id'),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
