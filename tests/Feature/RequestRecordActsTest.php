<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\RequestController;
use App\Models\Attachment;
use App\Models\Committee;
use App\Models\Decision;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestCorrection;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\EmployeeNoticeService;
use App\Services\Lifecycle\CorrectionRules;
use App\Services\Lifecycle\WithdrawalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Decision wizard, sub-project 3 — a file's records and its own content, as
 * the request payload offers them. Same no-drift rule as RequestActsTest.
 */
class RequestRecordActsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_extracted_refusals_answer_as_their_endpoints_did(): void
    {
        $recorder = $this->userWithRole('R02');
        $file = $this->fileAt('receive_from_committee', 'ready');
        $correction = RequestCorrection::create([
            'request_id' => $file->id, 'error_kind' => 'name', 'detail' => 'خطأ', 'incorrect_value' => 'أ',
            'corrected_value' => 'ب', 'recorded_by_user_id' => $recorder->id,
        ]);

        $this->assertSame(
            'لا يعتمد مذكرة التصحيح من حررها؛ يلزم اعتمادها من مسؤول آخر.',
            app(CorrectionRules::class)->approvalRefusal($correction, $recorder),
        );
        $this->assertNull(app(CorrectionRules::class)->approvalRefusal($correction, $this->userWithRole('R02')));

        $this->assertNull(app(WithdrawalService::class)->filingRefusal($file));
        DB::table('request_withdrawals')->insert(['request_id' => $file->id, 'reason' => 'رغبة', 'requested_at' => now()]);
        $this->assertSame('يوجد طلب سحب لم يبت فيه بعد.', app(WithdrawalService::class)->filingRefusal($file));

        // Created directly, so no status history: Art. 101 names no moment.
        $this->assertSame(
            'لا تستوجب حالة المعاملة الحالية إشعارًا وفق المادة 101.',
            app(EmployeeNoticeService::class)->issueRefusal($file),
        );

        $this->assertSame(
            'لا يمكن إعادة عرض طلب لم تُختتم إجراءاته بعد.',
            RequestController::reopenRefusal($file, $recorder),
        );
        $this->assertSame(
            'لا يجوز لمقدّم الطلب أو صاحب العلاقة إعادة فتح الطلب بنفسه.',
            RequestController::reopenRefusal($file, $file->createdBy),
        );

        $this->assertNull($file->attachmentRefusal());
    }

    public function test_each_open_record_is_its_own_act_with_its_own_target(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $file = $this->fileAt('receive_from_committee', 'ready');
        $first = DB::table('request_document_conflicts')->insertGetId(['request_id' => $file->id, 'conflict_kind' => 'grade', 'detail' => 'الدرجة']);
        $second = DB::table('request_document_conflicts')->insertGetId(['request_id' => $file->id, 'conflict_kind' => 'name', 'detail' => 'الاسم']);

        $resolve = collect($this->acts($rapporteur, $file)['available'])->where('action', 'resolve_document_conflict')->values();
        $this->assertSame([$first, $second], $resolve->pluck('target.id')->all());
        $this->assertSame(['grade', 'name'], $resolve->pluck('target.kind')->all());

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$file->id}/document-conflicts/{$first}/resolve", [
                'authority_consulted' => 'الموارد البشرية', 'authoritative_document' => 'القرار', 'correction_note' => 'صحح',
            ])
            ->assertOk();

        $remaining = collect($this->acts($rapporteur, $file)['available'])->where('action', 'resolve_document_conflict');
        $this->assertSame([$second], $remaining->pluck('target.id')->values()->all());
    }

    public function test_the_recorder_sees_their_own_correction_blocked_with_the_endpoints_reason(): void
    {
        $recorder = $this->userWithRole('R02');
        $file = $this->fileAt('receive_from_committee', 'ready');
        $id = DB::table('request_corrections')->insertGetId([
            'request_id' => $file->id, 'error_kind' => 'name', 'detail' => 'خطأ', 'incorrect_value' => 'أ',
            'corrected_value' => 'ب', 'recorded_by_user_id' => $recorder->id,
        ]);
        $reason = 'لا يعتمد مذكرة التصحيح من حررها؛ يلزم اعتمادها من مسؤول آخر.';

        $this->assertSame($reason, $this->blockedReason($recorder, $file, 'approve_correction'));
        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$file->id}/corrections/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('errors.correction.0', $reason);

        $this->assertContains('approve_correction', $this->available($this->userWithRole('R02'), $file));
    }

    public function test_a_withdrawal_is_the_filers_act_and_a_second_waits_for_the_first(): void
    {
        $filer = $this->userWithRole('R01');
        $file = $this->fileAt('receive_from_committee', 'ready', creator: $filer);

        $this->assertContains('file_withdrawal', $this->available($filer, $file));
        $this->assertNotContains('file_withdrawal', $this->available($this->userWithRole('R02'), $file));

        $this->actingAs($filer, 'sanctum')->postJson("/api/requests/{$file->id}/withdrawals", ['reason' => 'رغبة'])->assertCreated();
        $reason = 'يوجد طلب سحب لم يبت فيه بعد.';

        $this->assertSame($reason, $this->blockedReason($filer, $file, 'file_withdrawal'));
        $this->actingAs($filer, 'sanctum')
            ->postJson("/api/requests/{$file->id}/withdrawals", ['reason' => 'مرة أخرى'])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', $reason);
    }

    public function test_a_determination_offers_only_the_outcomes_the_endpoint_accepts(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $undecided = $this->fileAt('receive_from_committee', 'ready');
        $decided = $this->fileAt('final_approval_archiving', 'final_approved', decided: true);

        foreach ([$undecided, $decided] as $file) {
            DB::table('request_withdrawals')->insert(['request_id' => $file->id, 'reason' => 'رغبة', 'requested_at' => now()]);
        }
        $outcomes = fn (Request $file) => collect($this->acts($rapporteur, $file)['available'])->firstWhere('action', 'determine_withdrawal')['outcomes'];

        $this->assertSame(['granted', 'refused_administrative_continuation'], $outcomes($undecided));
        $this->assertSame(['refused_administrative_continuation', 'recorded_only'], $outcomes($decided));
    }

    public function test_a_notice_is_blocked_when_the_state_warrants_none(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $file = $this->fileAt('receive_from_committee', 'ready');
        $reason = 'لا تستوجب حالة المعاملة الحالية إشعارًا وفق المادة 101.';

        $this->assertSame($reason, $this->blockedReason($rapporteur, $file, 'issue_notice'));
        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$file->id}/notices/issue")
            ->assertStatus(422)
            ->assertJsonPath('message', $reason);
    }

    public function test_reopen_is_offered_on_a_concluded_file_and_refused_to_its_filer(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $theirs = $this->fileAt('requirements_check', 'not_approved');
        $own = $this->fileAt('requirements_check', 'not_approved', creator: $rapporteur);
        $reason = 'لا يجوز لمقدّم الطلب أو صاحب العلاقة إعادة فتح الطلب بنفسه.';

        $this->assertContains('reopen', $this->available($rapporteur, $theirs));
        $this->assertSame($reason, $this->blockedReason($rapporteur, $own, 'reopen'));
        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$own->id}/reopen", [
                'reason_code' => 'new_document',
                'target_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', $reason);
    }

    public function test_content_acts_follow_their_grants(): void
    {
        $filer = $this->userWithRole('R01');
        // A returned file sits at intake — the one stage the filer attaches at
        // (FilerAttachmentsAndReturnTest builds the same state).
        $atIntake = $this->fileAt('receive_from_municipality', 'returned', creator: $filer);
        $atCommittee = $this->fileAt('receive_from_committee', 'ready', creator: $filer);
        $rapporteur = $this->userWithRole('R02');

        $this->assertContains('attach_document', $this->available($filer, $atIntake));
        $this->assertNotContains('attach_document', $this->available($filer, $atCommittee));
        $this->assertContains('add_note', $this->available($filer, $atCommittee));

        $impact = collect($this->acts($rapporteur, $atCommittee)['available'])->firstWhere('action', 'set_financial_impact');
        $this->assertSame((bool) $atCommittee->has_financial_impact, $impact['has_financial_impact']);
        $this->assertNotContains('set_financial_impact', $this->available($filer, $atCommittee));
    }

    public function test_an_open_vote_blocks_attaching_as_the_upload_endpoint_refuses_it(): void
    {
        $filer = $this->userWithRole('R01');
        $file = $this->fileAt('receive_from_municipality', 'returned', creator: $filer);
        $meeting = Meeting::create([
            'committee_id' => Committee::create(['name_ar' => 'لجنة'])->id,
            'title' => 'اجتماع', 'scheduled_at' => now(),
        ]);
        $item = MeetingRequest::create(['meeting_id' => $meeting->id, 'request_id' => $file->id, 'agenda_order' => 1]);
        DB::table('votes')->insert([
            'meeting_request_id' => $item->id, 'user_id' => $this->userWithRole('R04')->id,
            'vote' => 'approve', 'voted_at' => now(),
        ]);
        $reason = 'بدأ التصويت على هذا الموضوع في اللجنة، ولا يجوز إضافة مستندات قبل إثبات النتيجة.';

        $this->assertSame($reason, $this->blockedReason($filer, $file, 'attach_document'));
        $this->actingAs($filer->fresh(), 'sanctum')
            ->post("/api/requests/{$file->id}/attachments", [
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
                'required_document_key' => RequestType::OTHER_DOCUMENT,
                'file_section' => Attachment::DEFAULT_SUBMITTER_SECTION,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', $reason);
    }

    public function test_a_notice_is_blocked_when_the_subject_has_no_active_account(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $file = $this->fileAt('receive_from_committee', 'on_agenda');
        // on_agenda is Art. 101's placed_on_agenda moment.
        RequestStatusHistory::create([
            'request_id' => $file->id,
            'from_status_id' => RequestStatus::where('code', 'ready')->value('id'),
            'to_status_id' => RequestStatus::where('code', 'on_agenda')->value('id'),
            'changed_at' => now(),
        ]);
        $file->subject->update(['is_active' => false]);
        $reason = 'لا يمكن إشعار صاحب العلاقة: لا يوجد له حساب مفعّل.';

        $this->assertSame($reason, $this->blockedReason($rapporteur, $file, 'issue_notice'));
        $this->actingAs($rapporteur->fresh(), 'sanctum')
            ->postJson("/api/requests/{$file->id}/notices/issue")
            ->assertStatus(422)
            ->assertJsonPath('message', $reason);
    }

    public function test_a_closed_file_takes_no_new_conflict_or_special_case(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $file = $this->fileAt('final_approval_archiving', 'completed_closed', decided: true);

        $this->assertContains('record_document_conflict', $this->available($rapporteur, $file));

        $file->update(['closed_at' => now()]);
        $acts = $this->acts($rapporteur, $file);
        $offered = array_column([...$acts['available'], ...$acts['blocked']], 'action');
        $this->assertNotContains('record_document_conflict', $offered);
        $this->assertNotContains('record_special_case', $offered);
    }

    // --- helpers ------------------------------------------------------------

    /** @return array{available: list<array<string, mixed>>, blocked: list<array<string, mixed>>} */
    private function acts(User $actor, Request $file): array
    {
        return $this->actingAs($actor->fresh(), 'sanctum')
            ->getJson("/api/requests/{$file->id}")
            ->assertOk()
            ->json('data.acts');
    }

    /** @return list<string> */
    private function available(User $actor, Request $file): array
    {
        return array_column($this->acts($actor, $file)['available'], 'action');
    }

    private function blockedReason(User $actor, Request $file, string $action): ?string
    {
        return collect($this->acts($actor, $file)['blocked'])->firstWhere('action', $action)['reason'] ?? null;
    }

    private function fileAt(string $stageCode, string $statusCode, bool $decided = false, ?User $creator = null): Request
    {
        $creator ??= $this->userWithRole('R01');

        $file = Request::create([
            'reference_number' => 'PM-COM/2026/'.fake()->unique()->numerify('####'),
            'title' => 'معاملة '.fake()->unique()->numerify('###'),
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now()->subMonth(),
        ]);

        if ($decided) {
            $meeting = Meeting::create([
                'committee_id' => Committee::create(['name_ar' => 'لجنة'])->id,
                'title' => 'اجتماع',
                'status' => 'completed',
                'scheduled_at' => now()->subWeek(),
            ]);
            $item = MeetingRequest::create(['meeting_id' => $meeting->id, 'request_id' => $file->id, 'agenda_order' => 1]);
            Decision::create(['meeting_request_id' => $item->id, 'outcome' => 'approve', 'decided_at' => now()->subWeek()]);
        }

        return $file->fresh();
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
