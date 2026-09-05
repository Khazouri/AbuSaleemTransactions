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
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\RequestStageChangedNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stage 61, Track J — AppealFileCompiler: the assembled original-matter
 * dossier (original request, presentation memo, meeting minutes excerpt,
 * decision, notification evidence, and the appeal's own documents) reached
 * through GET /appeals/{appeal}/file. See AGENT_NOTES.md for why the memo's
 * derived half is always recomputed live while the minutes are read from
 * whatever was persisted (never live-recompiled), and for the
 * AppealAttachmentController visibility gap this stage closes.
 */
class AppealFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_shows_the_full_dossier_for_a_committee_decided_appeal(): void
    {
        $appellant = $this->userWithRole('R01');
        [$appeal, $head, , , $agendaItem, $target] = $this->decidedAppealFixture($appellant, withMemo: true, withMinutes: true);

        Storage::fake('local');
        $this->actingAs($appellant, 'sanctum')
            ->post("/api/appeals/{$appeal->id}/attachments", [
                'file' => UploadedFile::fake()->create('evidence.pdf', 10, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $response = $this->actingAs($appellant, 'sanctum')
            ->getJson("/api/appeals/{$appeal->id}/file")
            ->assertOk();

        $response->assertJsonPath('data.original_request.id', $target->id);
        $response->assertJsonPath('data.original_request.reference_number', $target->reference_number);
        $response->assertJsonPath('data.original_request.created_by.id', $appellant->id);
        $this->assertCount(0, $response->json('data.original_request.attachments'));

        $response->assertJsonPath('data.presentation_memo.derived.reference_number', $target->reference_number);
        $response->assertJsonPath('data.presentation_memo.authored.facts_summary', 'ملخص وقائع التظلم الأصلي.');
        $response->assertJsonPath('data.presentation_memo.authored.legal_opinion', 'الأساس القانوني للقرار الأصلي.');

        $response->assertJsonPath('data.meeting_minutes.status', 'draft');
        $response->assertJsonPath('data.meeting_minutes.agenda_item.id', $agendaItem->id);
        $response->assertJsonPath('data.meeting_minutes.agenda_item.facts_summary', 'ملخص وقائع التظلم الأصلي.');

        $response->assertJsonPath('data.decision.outcome', 'approve');
        $response->assertJsonPath('data.decision.decided_by.id', $head->id);
        $this->assertNull($response->json('data.decision_reference_fallback'));

        $this->assertCount(1, $response->json('data.appeal_documents'));
        $response->assertJsonPath('data.appeal_documents.0.original_name', 'evidence.pdf');

        $response->assertJsonPath('data.notification_evidence.email.evidence_available', false);
        $response->assertJsonPath('data.notification_evidence.sms.evidence_available', false);
    }

    public function test_presentation_memo_shows_derived_fields_without_a_persisted_memo_row(): void
    {
        $appellant = $this->userWithRole('R01');
        [$appeal, , , , , $target] = $this->decidedAppealFixture($appellant, withMemo: false, withMinutes: true);

        $response = $this->actingAs($appellant, 'sanctum')
            ->getJson("/api/appeals/{$appeal->id}/file")
            ->assertOk();

        $response->assertJsonPath('data.presentation_memo.derived.reference_number', $target->reference_number);
        $this->assertNull($response->json('data.presentation_memo.authored'));
        // Minutes were still generated independently of the memo.
        $response->assertJsonPath('data.meeting_minutes.status', 'draft');
    }

    public function test_meeting_minutes_are_null_when_never_generated(): void
    {
        $appellant = $this->userWithRole('R01');
        [$appeal] = $this->decidedAppealFixture($appellant, withMemo: true, withMinutes: false);

        $this->actingAs($appellant, 'sanctum')
            ->getJson("/api/appeals/{$appeal->id}/file")
            ->assertOk()
            ->assertJsonPath('data.meeting_minutes', null);
    }

    public function test_returns_the_free_text_fallback_when_there_is_no_recorded_decision(): void
    {
        $appellant = $this->userWithRole('R01');
        $target = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب مرفوض شكلياً بلا قرار لجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'rejected')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'requirements_check')->value('id'),
            'created_by_user_id' => $appellant->id,
            'submitted_at' => now()->subMonth(),
        ]);

        $appeal = Appeal::create([
            'appellant_user_id' => $appellant->id,
            'original_request_id' => $target->id,
            'original_decision_reference' => 'قرار رفض شكلي رقم 9',
            'original_decision_date' => now()->subWeek()->toDateString(),
            'known_at' => now()->subDay(),
            'appeal_reasons' => 'الرفض لم يكن مسبباً بشكل كافٍ.',
            'final_request' => 'إعادة النظر في القرار.',
            'appeal_status_id' => AppealStatus::where('code', 'submitted')->value('id'),
        ]);

        $response = $this->actingAs($appellant, 'sanctum')
            ->getJson("/api/appeals/{$appeal->id}/file")
            ->assertOk();

        $this->assertNull($response->json('data.presentation_memo'));
        $this->assertNull($response->json('data.meeting_minutes'));
        $this->assertNull($response->json('data.decision'));
        $response->assertJsonPath('data.decision_reference_fallback.reference', 'قرار رفض شكلي رقم 9');
    }

    public function test_notification_evidence_lists_only_in_app_notifications_addressed_to_the_appellant_about_that_request(): void
    {
        $appellant = $this->userWithRole('R01');
        $stranger = $this->userWithRole('R01');
        [$appeal, , , , , $target] = $this->decidedAppealFixture($appellant, withMemo: false, withMinutes: false);

        // The fixture's own vote/decision recording already dispatches real
        // stage_changed + decision_recorded notifications to the appellant
        // about $target (they're its creator) — count those first so the
        // assertions below prove scoping, not a hardcoded total that system
        // notifications would throw off.
        $baseline = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $appellant->id)
            ->where('data->request_id', $target->id)
            ->count();

        $unrelatedRequest = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب آخر لا علاقة له بالتظلم',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_review')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'requirements_check')->value('id'),
            'created_by_user_id' => $appellant->id,
            'submitted_at' => now(),
        ]);

        // The one that should show up.
        $appellant->notify(new RequestStageChangedNotification($target, null, null, 'forward', 'مختبِر'));
        // Same appellant, but about a different request — must not show up.
        $appellant->notify(new RequestStageChangedNotification($unrelatedRequest, null, null, 'forward', 'مختبِر'));
        // A stranger notified about the same request — must not show up either.
        $stranger->notify(new RequestStageChangedNotification($target, null, null, 'forward', 'مختبِر'));

        $response = $this->actingAs($appellant, 'sanctum')
            ->getJson("/api/appeals/{$appeal->id}/file")
            ->assertOk();

        $inApp = $response->json('data.notification_evidence.in_app');
        $this->assertCount($baseline + 1, $inApp);
        foreach ($inApp as $notice) {
            $this->assertSame($target->id, $notice['request_id']);
        }
        $response->assertJsonPath('data.notification_evidence.email.evidence_available', false);
        $response->assertJsonPath('data.notification_evidence.sms.evidence_available', false);
    }

    public function test_a_stranger_gets_404_on_file_and_attachment_preview(): void
    {
        $appellant = $this->userWithRole('R01');
        $stranger = $this->userWithRole('R01');
        [$appeal] = $this->decidedAppealFixture($appellant, withMemo: false, withMinutes: false);

        Storage::fake('local');
        $attachment = AppealAttachment::create([
            'appeal_id' => $appeal->id,
            'disk' => 'local',
            'path' => "appeal-attachments/{$appeal->id}/evidence.pdf",
            'original_name' => 'evidence.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
        ]);
        Storage::disk('local')->put($attachment->path, 'pdf-bytes');

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/appeals/{$appeal->id}/file")
            ->assertNotFound();

        $this->actingAs($stranger, 'sanctum')
            ->get(route('appeals.attachments.preview', ['appeal' => $appeal, 'attachment' => $attachment]))
            ->assertNotFound();
    }

    /** Closes the visibility gap: AppealAttachmentController now agrees with AppealController::index()'s scoping. */
    public function test_r08_and_an_edit_permission_holder_can_view_a_file_they_did_not_file(): void
    {
        $appellant = $this->userWithRole('R01');
        $reviewer = $this->userWithRole('R02'); // holds appeals,edit per ScreenRolePermissionSeeder
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        [$appeal] = $this->decidedAppealFixture($appellant, withMemo: false, withMinutes: false);

        Storage::fake('local');
        $attachment = AppealAttachment::create([
            'appeal_id' => $appeal->id,
            'disk' => 'local',
            'path' => "appeal-attachments/{$appeal->id}/evidence.pdf",
            'original_name' => 'evidence.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
        ]);
        Storage::disk('local')->put($attachment->path, 'pdf-bytes');

        foreach ([$reviewer, $admin] as $actor) {
            $this->actingAs($actor, 'sanctum')
                ->getJson("/api/appeals/{$appeal->id}/file")
                ->assertOk();

            $this->actingAs($actor, 'sanctum')
                ->get(route('appeals.attachments.preview', ['appeal' => $appeal, 'attachment' => $attachment]))
                ->assertOk();
        }
    }

    // --- helpers --------------------------------------------------------------

    /**
     * A request decided by a real committee vote, with an optional generated+
     * authored presentation memo and/or generated (draft) meeting minutes. A
     * second, unrelated agenda item rides the same meeting so tests can prove
     * the compiler matches the right one, not just "an" item.
     *
     * @return array{0: Appeal, 1: User, 2: User, 3: Meeting, 4: MeetingRequest, 5: Request, 6: Decision}
     */
    private function decidedAppealFixture(User $appellant, bool $withMemo, bool $withMinutes): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');

        $committee = Committee::create(['name_ar' => 'لجنة اختبار ملف التظلم']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true, 'seat' => 'chair']);
        $committee->members()->create(['user_id' => $member->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع اختبار ملف التظلم',
            'scheduled_at' => now()->subWeek(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);
        $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);

        $unrelatedRequest = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'بند آخر بنفس الاجتماع',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $this->userWithRole('R01')->id,
            'submitted_at' => now()->subMonth(),
        ]);
        $meeting->agendaItems()->create(['request_id' => $unrelatedRequest->id, 'agenda_order' => 1]);

        $target = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب صدر بشأنه قرار لجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $appellant->id,
            'submitted_at' => now()->subMonth(),
        ]);
        $agendaItem = $meeting->agendaItems()->create(['request_id' => $target->id, 'agenda_order' => 2]);

        if ($withMemo) {
            $this->actingAs($head, 'sanctum')
                ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/presentation-memo/generate")
                ->assertOk();
            $this->actingAs($head, 'sanctum')
                ->patchJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/presentation-memo", [
                    'facts_summary' => 'ملخص وقائع التظلم الأصلي.',
                    'legal_opinion' => 'الأساس القانوني للقرار الأصلي.',
                ])
                ->assertOk();
        }

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", [
                'signature' => UploadedFile::fake()->image('signature.png', 10, 10),
                'referral_authority' => 'ديوان البلدية',
            ])
            ->assertCreated();

        if ($withMinutes) {
            $this->actingAs($head, 'sanctum')
                ->postJson("/api/meetings/{$meeting->id}/minutes/generate")
                ->assertOk();
        }

        $decision = Decision::where('meeting_request_id', $agendaItem->id)->firstOrFail();

        $appeal = Appeal::create([
            'appellant_user_id' => $appellant->id,
            'original_request_id' => $target->id,
            'original_decision_id' => $decision->id,
            'known_at' => now()->subDay(),
            'appeal_reasons' => 'القرار خالف الإجراءات المتبعة.',
            'final_request' => 'إعادة النظر في القرار.',
            'appeal_status_id' => AppealStatus::where('code', 'submitted')->value('id'),
        ]);

        return [$appeal, $head, $member, $meeting, $agendaItem, $target, $decision];
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
