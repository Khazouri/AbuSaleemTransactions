<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Attachment;
use App\Models\Committee;
use App\Models\Decision;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStageLog;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stage 80 — [D] Art. 100's السجل الزمني, completed.
 *
 * The article names six columns; Stage 14 wrote four of them. These cover the
 * two this stage added: **الجهة** and **المستند المرتبط**.
 */
class RequestTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * الجهة is the acting user's own department, and falls back to the stage's
     * seeded responsible role for a system move that has no actor at all.
     */
    public function test_each_entry_names_the_body_that_held_the_file(): void
    {
        $department = Department::query()->where('code', 'ADM')->firstOrFail();
        $actor = $this->userWithRole('R02');
        $requestRecord = $this->fixtureRequest($actor);

        $this->log($requestRecord, $actor, 'forward', now()->subDays(5));
        // A system move — Stage 70's intake auto-hop has no human actor.
        $this->log($requestRecord, null, 'submit', now()->subDays(4));

        $timeline = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->json('data.timeline');

        $this->assertSame('department', $timeline[0]['body']['kind']);
        $this->assertSame($department->name_ar, $timeline[0]['body']['name_ar']);
        // The actorless entry still names a body, from the stage's own roster.
        $this->assertSame('role', $timeline[1]['body']['kind']);
        $this->assertNotNull($timeline[1]['body']['name_ar']);
    }

    /**
     * المستند المرتبط links the three kinds of artifact a step can produce, and
     * attributes each to the entry whose window it falls in — a document that
     * arrived under the next holder belongs to them, not to this entry.
     */
    public function test_documents_are_attributed_to_the_step_whose_window_they_fall_in(): void
    {
        Storage::fake('local');
        $actor = $this->userWithRole('R02');
        $requestRecord = $this->fixtureRequest($actor);

        $first = $this->log($requestRecord, $actor, 'forward', now()->subDays(5));
        $second = $this->log($requestRecord, $actor, 'approve', now()->subDays(3));

        // Arrived while the file stood where the FIRST entry put it.
        $early = Attachment::create([
            'request_id' => $requestRecord->id,
            'disk' => 'local',
            'path' => 'attachments/early.pdf',
            'original_name' => 'early.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'label' => 'كشف الخدمة',
            'file_section' => 'service_file',
            'uploaded_by_user_id' => $actor->id,
            'created_at' => now()->subDays(4),
            'updated_at' => now()->subDays(4),
        ]);
        // Arrived after the second move, so it belongs to the second entry.
        $late = Attachment::create([
            'request_id' => $requestRecord->id,
            'disk' => 'local',
            'path' => 'attachments/late.pdf',
            'original_name' => 'late.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'file_section' => 'approval',
            'uploaded_by_user_id' => $actor->id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        // The approval this second entry actually produced.
        Approval::create([
            'request_id' => $requestRecord->id,
            'level' => 1,
            'role_id' => Role::query()->where('code', 'R02')->value('id'),
            'approved_by_user_id' => $actor->id,
            'action' => 'approve',
            'signature_path' => 'signatures/1.png',
            'approved_at' => now()->subDays(3),
        ]);

        $timeline = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->json('data.timeline');

        $this->assertSame($first->id, $timeline[0]['id']);
        $this->assertSame(
            [['attachment', $early->id === null ? null : 'كشف الخدمة']],
            array_map(fn ($d) => [$d['kind'], $d['label']], $timeline[0]['documents']),
        );
        // Appendix 14's folder travels with the document.
        $this->assertSame('service_file', $timeline[0]['documents'][0]['section']);

        $this->assertSame($second->id, $timeline[1]['id']);
        $kinds = array_column($timeline[1]['documents'], 'kind');
        $this->assertContains('approval_signature', $kinds);
        $this->assertContains('attachment', $kinds);
        $this->assertSame(
            $late->original_name,
            collect($timeline[1]['documents'])->firstWhere('kind', 'attachment')['reference'],
        );
    }

    /** A recorded committee decision is the document of the step that recorded it. */
    public function test_a_recorded_decision_is_linked_to_the_step_that_recorded_it(): void
    {
        $actor = $this->userWithRole('R03');
        $requestRecord = $this->fixtureRequest($actor);

        $this->log($requestRecord, $actor, 'approve', now()->subDays(2));

        $committee = Committee::create(['name_ar' => 'لجنة']);
        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع',
            'scheduled_at' => now()->subDays(2),
            'created_by_user_id' => $actor->id,
        ]);
        $agendaItem = MeetingRequest::create([
            'meeting_id' => $meeting->id,
            'request_id' => $requestRecord->id,
            'agenda_order' => 1,
        ]);
        Decision::create([
            'meeting_request_id' => $agendaItem->id,
            'decision_number' => 'PM-DEC/2026/001',
            'outcome' => 'approve',
            'instrument' => 'decision',
            'decision_subject' => 'الترقية',
            'decision_operative' => 'قررت اللجنة الموافقة.',
            'decided_by_user_id' => $actor->id,
            'decided_at' => now()->subDay(),
        ]);

        $timeline = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->json('data.timeline');

        $decision = collect($timeline[0]['documents'])->firstWhere('kind', 'decision');
        $this->assertNotNull($decision);
        $this->assertSame('PM-DEC/2026/001', $decision['reference']);
    }

    /** An entry with no document says so, rather than borrowing its neighbour's. */
    public function test_an_entry_with_no_document_reports_an_empty_list(): void
    {
        $actor = $this->userWithRole('R02');
        $requestRecord = $this->fixtureRequest($actor);
        $this->log($requestRecord, $actor, 'forward', now()->subDay());

        $timeline = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->json('data.timeline');

        $this->assertSame([], $timeline[0]['documents']);
    }

    /**
     * Appendix 14 — "ويمنع حفظ الملفات بصورة عشوائية دون تصنيف", so a new
     * upload is classified or it is refused.
     *
     * Stage 91 changed WHICH question does the classifying, not whether one is
     * asked: the Appendix 57 row is the question now, and Appendix 14's folder
     * survives for `other` — the one answer that leaves a folder unstated.
     */
    public function test_an_upload_must_be_classified_before_it_is_stored(): void
    {
        Storage::fake('local');
        $actor = $this->userWithRole('R02');
        $requestRecord = $this->fixtureRequest($actor);

        $this->actingAs($actor, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('required_document_key');

        // `other` is what makes Appendix 14's folder a real question again.
        $this->actingAs($actor, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
                'required_document_key' => 'other',
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file_section');

        $this->actingAs($actor, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
                'required_document_key' => 'other',
                'file_section' => 'not-a-folder',
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file_section');

        $this->actingAs($actor, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
                'required_document_key' => 'other',
                'file_section' => 'supporting_documents',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.file_section', 'supporting_documents');
    }

    private function log(Request $requestRecord, ?User $actor, string $action, $actedAt): RequestStageLog
    {
        return RequestStageLog::create([
            'request_id' => $requestRecord->id,
            'from_stage_id' => $requestRecord->current_stage_id,
            'to_stage_id' => $requestRecord->current_stage_id,
            'action' => $action,
            'acted_by_user_id' => $actor?->id,
            'acted_at' => $actedAt,
        ]);
    }

    private function fixtureRequest(User $creator): Request
    {
        return Request::create([
            'reference_number' => 'PM-COM/2026/0001',
            'title' => 'طلب ترقية',
            'department_id' => Department::query()->where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::query()->value('id'),
            'status_id' => RequestStatus::where('code', 'registered')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'reviewer_review')->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now()->subDays(7),
        ]);
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
