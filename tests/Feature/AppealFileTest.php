<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AppealAttachment;
use App\Models\AppealStatus;
use App\Models\Attachment;
use App\Models\Decision;
use App\Models\Department;
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
use Tests\HoldsPaperMeetings;
use Tests\TestCase;

/**
 * Stage 61, Track J — AppealFileCompiler: the assembled original-matter
 * dossier (original request, decision, notification evidence, and the
 * appeal's own documents) reached through GET /appeals/{appeal}/file. See
 * AGENT_NOTES.md for the AppealAttachmentController visibility gap this
 * stage closes.
 */
class AppealFileTest extends TestCase
{
    use HoldsPaperMeetings;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_shows_the_full_dossier_for_a_committee_decided_appeal(): void
    {
        $appellant = $this->userWithRole('R01');
        [$appeal, $rapporteur, $target] = $this->decidedAppealFixture($appellant);

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

        $response->assertJsonPath('data.decision.outcome', 'approve');
        $response->assertJsonPath('data.decision.decided_by.id', $rapporteur->id);
        $this->assertNull($response->json('data.decision_reference_fallback'));

        $this->assertCount(1, $response->json('data.appeal_documents'));
        $response->assertJsonPath('data.appeal_documents.0.original_name', 'evidence.pdf');

        $response->assertJsonPath('data.notification_evidence.email.evidence_available', false);
        $response->assertJsonPath('data.notification_evidence.sms.evidence_available', false);
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

        $this->assertNull($response->json('data.decision'));
        $response->assertJsonPath('data.decision_reference_fallback.reference', 'قرار رفض شكلي رقم 9');
    }

    public function test_notification_evidence_lists_only_in_app_notifications_addressed_to_the_appellant_about_that_request(): void
    {
        $appellant = $this->userWithRole('R01');
        $stranger = $this->userWithRole('R01');
        [$appeal, , $target] = $this->decidedAppealFixture($appellant);

        // The fixture's recorded results may already notify the appellant
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
        [$appeal] = $this->decidedAppealFixture($appellant);

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
        [$appeal] = $this->decidedAppealFixture($appellant);

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

    /**
     * The original request's documents open through the appeal itself, so a
     * reader who may see the appeal but not the original file can still preview
     * them, and only documents of that one request are served.
     */
    public function test_original_request_documents_preview_through_the_appeal(): void
    {
        $appellant = $this->userWithRole('R01');
        $reviewer = $this->userWithRole('R02');
        $stranger = $this->userWithRole('R01');
        [$appeal, , $target] = $this->decidedAppealFixture($appellant);

        $document = $this->storedAttachment($target, 'service.pdf');
        $foreign = $this->storedAttachment(
            Request::where('id', '!=', $target->id)->firstOrFail(),
            'other.pdf',
        );

        $url = route('appeals.original-documents.preview', ['appeal' => $appeal, 'attachment' => $document]);

        $response = $this->actingAs($appellant, 'sanctum')
            ->getJson("/api/appeals/{$appeal->id}/file")
            ->assertOk();
        $response->assertJsonPath('data.original_request.attachments.0.preview_url', $url);

        foreach ([$appellant, $reviewer] as $actor) {
            $this->actingAs($actor, 'sanctum')->get($url)->assertOk();
        }

        $this->actingAs($stranger, 'sanctum')->get($url)->assertNotFound();
        $this->actingAs($appellant, 'sanctum')
            ->get(route('appeals.original-documents.preview', ['appeal' => $appeal, 'attachment' => $foreign]))
            ->assertNotFound();
    }

    // --- helpers --------------------------------------------------------------

    private function storedAttachment(Request $requestRecord, string $name): Attachment
    {
        $attachment = Attachment::create([
            'request_id' => $requestRecord->id,
            'disk' => 'local',
            'path' => "attachments/{$requestRecord->id}/{$name}",
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'size_bytes' => 9,
            'file_section' => 'service_file',
        ]);
        Storage::disk('local')->put($attachment->path, 'pdf-bytes');

        return $attachment;
    }

    /**
     * A request the committee approved on paper. A second, unrelated agenda
     * item rides the same meeting so tests can prove the dossier picks the
     * right decision, not just "an" item's.
     *
     * @return array{0: Appeal, 1: User, 2: Request}
     */
    private function decidedAppealFixture(User $appellant): array
    {
        [$rapporteur, $meeting, [$unrelated, $agendaItem]] = $this->paperMeeting(2);
        $target = $agendaItem->request;
        $target->update(['created_by_user_id' => $appellant->id, 'subject_user_id' => $appellant->id]);

        $this->recordResults($rapporteur, $meeting, [
            $unrelated->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
            $agendaItem->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
        ])->assertOk();

        $appeal = Appeal::create([
            'appellant_user_id' => $appellant->id,
            'original_request_id' => $target->id,
            'original_decision_id' => Decision::where('meeting_request_id', $agendaItem->id)->value('id'),
            'known_at' => now()->subDay(),
            'appeal_reasons' => 'القرار خالف الإجراءات المتبعة.',
            'final_request' => 'إعادة النظر في القرار.',
            'appeal_status_id' => AppealStatus::where('code', 'submitted')->value('id'),
        ]);

        return [$appeal, $rapporteur, $target->fresh()];
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
