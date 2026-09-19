<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestDraft;
use App\Models\RequestDraftAttachment;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Services\DocumentCompletenessService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\PassesControlGates;
use Tests\TestCase;

/**
 * Stage 90 — the intake form matches [G]'s own «PDF أو صورة واضحة» and
 * «الأسباب» items, and a rejected file no longer costs the rest of a
 * selection.
 *
 * The live per-field validation and the `*` convention are client-side (see
 * RequestIntakeView.vue) and are not asserted here — this file covers the
 * three items with a server-checkable effect: the narrowed file types (on
 * BOTH intake paths, since Stage 88's `attachments.*` rules never run for a
 * draft-backed submission), the deliberate asymmetry that the shared
 * committee-cycle upload endpoint is untouched, and the new `reasons` field.
 *
 * ALLW is used throughout for a request that must actually SUCCEED: every
 * type since Stage 85 carries a `decision_grade` threshold and at least one
 * mandatory Appendix 57 row, and ALLW's is the smallest (one row), so the
 * fixtures stay about file types and `reasons`, not Stage 85's own rule.
 */
class IntakeFidelityTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;

    public function test_intake_refuses_a_word_document_and_accepts_a_pdf(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $employee = $this->userWithRole('R01');
        $department = Department::where('code', 'ADM')->firstOrFail();
        $type = RequestType::where('code', 'ALLW')->firstOrFail();
        $mandatoryKey = $this->mandatoryKeyFor($type);

        $this->actingAs($employee, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب بمستند وورد',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
                'attachments' => [[
                    'file' => UploadedFile::fake()->create('memo.docx', 40, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                    'required_document_key' => $mandatoryKey,
                ]],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments.0.file');

        $this->assertSame(0, Request::count());

        $this->actingAs($employee, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب بمستند PDF',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
                'attachments' => [[
                    'file' => UploadedFile::fake()->create('memo.pdf', 40, 'application/pdf'),
                    'required_document_key' => $mandatoryKey,
                ]],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame(1, Request::count());
    }

    public function test_the_draft_attachment_endpoint_refuses_a_word_document_too(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $employee = $this->userWithRole('R02');

        $draftId = $this->actingAs($employee, 'sanctum')
            ->postJson('/api/requests/drafts', [])
            ->assertCreated()
            ->json('data.id');

        // Stage 88's per-file question intake asks never runs for a draft-
        // backed submission — this is the path Stage 90's own note flags as
        // the one that would silently accept a DOCX if only StoreRequest's
        // rule were narrowed.
        $this->actingAs($employee, 'sanctum')
            ->post("/api/requests/drafts/{$draftId}/attachments", [
                'file' => UploadedFile::fake()->create('memo.doc', 30, 'application/msword'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->actingAs($employee, 'sanctum')
            ->post("/api/requests/drafts/{$draftId}/attachments", [
                'file' => UploadedFile::fake()->create('memo.pdf', 30, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame(1, RequestDraftAttachment::count());
    }

    /**
     * The hole narrowing the draft endpoint opens on its own: a draft built
     * BEFORE this stage — or through any future caller that skips the
     * endpoint's own validation — could already hold a DOCX, and Stage 88's
     * `attachments.*` rules never run against a draft's files. Without a
     * check at submission that draft would still promote unchallenged.
     */
    public function test_a_draft_holding_a_word_document_is_refused_at_submission(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $employee = $this->userWithRole('R01');
        $department = Department::where('code', 'ADM')->firstOrFail();
        $type = RequestType::where('code', 'ALLW')->firstOrFail();

        $draft = RequestDraft::create([
            'created_by_user_id' => $employee->id,
            'payload' => [
                'title' => 'طلب من مسودة قديمة',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
            ],
        ]);

        RequestDraftAttachment::create([
            'request_draft_id' => $draft->id,
            'disk' => 'local',
            'path' => 'request-drafts/'.$draft->id.'/legacy.doc',
            'original_name' => 'legacy.doc',
            // The stored, server-verified fact — not the filename — is what
            // the refusal reads, per Stage 90's own note.
            'mime_type' => 'application/msword',
            'size_bytes' => 20,
            'required_document_key' => $this->mandatoryKeyFor($type),
        ]);

        $this->actingAs($employee, 'sanctum')
            ->post('/api/requests', [
                'draft_id' => $draft->id,
                'title' => 'طلب من مسودة قديمة',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments');

        $this->assertSame(0, Request::count());
        $this->assertNotNull($draft->fresh());
    }

    /**
     * The deliberate asymmetry, pinned so a later "consistency" pass has to be
     * a decision rather than an accident: the committee-cycle upload endpoint
     * is shared with R02–R05 filing genuine later-cycle documents and is NOT
     * narrowed — [D] names no file format anywhere, so the constraint is
     * [G]'s, and [G] specifies stage 1 (intake) only.
     */
    public function test_the_shared_committee_cycle_upload_still_accepts_a_word_document(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $officer = $this->userWithRole('R02');
        $department = Department::where('code', 'ADM')->firstOrFail();
        $type = RequestType::where('code', 'ALLW')->firstOrFail();

        $requestRecord = Request::create([
            'title' => 'طلب قائم',
            'department_id' => $department->id,
            'request_type_id' => $type->id,
            'created_by_user_id' => $officer->id,
        ]);

        // Stage 91 — this endpoint asks the same document question intake
        // does, but it is not narrowed by file type, only intake is.
        $this->actingAs($officer, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('minutes.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                'required_document_key' => 'other',
                'file_section' => 'minutes_decision',
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame(1, Attachment::count());
    }

    public function test_reasons_round_trips_through_intake_and_is_optional(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $employee = $this->userWithRole('R01');
        $department = Department::where('code', 'ADM')->firstOrFail();
        // Two different types — Appendix 16 refuses a second open file of the
        // same type by the same creator, and this test is about `reasons`,
        // not that rule (RequestIntakeTest's own precedent for this exact
        // workaround).
        $typeA = RequestType::where('code', 'ALLW')->firstOrFail();
        $typeB = RequestType::where('code', 'EOSV')->firstOrFail();

        // Optional: a submission with no `reasons` at all still succeeds.
        $this->actingAs($employee, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب بلا أسباب',
                'department_id' => $department->id,
                'request_type_id' => $typeA->id,
                'decision_grade' => 11,
                'attachments' => [[
                    'file' => UploadedFile::fake()->create('doc-a.pdf', 20, 'application/pdf'),
                    'required_document_key' => $this->mandatoryKeyFor($typeA),
                ]],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertNull(Request::firstOrFail()->reasons);

        $response = $this->actingAs($employee, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب بأسباب',
                'department_id' => $department->id,
                'request_type_id' => $typeB->id,
                'decision_grade' => 11,
                'reasons' => 'السبب هو ظروف عائلية طارئة.',
                'attachments' => [[
                    'file' => UploadedFile::fake()->create('doc-b.pdf', 20, 'application/pdf'),
                    'required_document_key' => $this->mandatoryKeyFor($typeB),
                ]],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $requestRecord = Request::findOrFail($response->json('data.id'));
        $this->assertSame('السبب هو ظروف عائلية طارئة.', $requestRecord->reasons);

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.reasons', 'السبب هو ظروف عائلية طارئة.');
    }

    public function test_reasons_is_saved_and_resumed_on_a_draft(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R02');

        $draftId = $this->actingAs($employee, 'sanctum')
            ->postJson('/api/requests/drafts', [])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($employee, 'sanctum')
            ->putJson("/api/requests/drafts/{$draftId}", ['reasons' => 'مسودة سبب'])
            ->assertOk();

        $this->assertSame('مسودة سبب', RequestDraft::findOrFail($draftId)->payload['reasons'] ?? null);

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/drafts/{$draftId}")
            ->assertOk()
            ->assertJsonPath('data.payload.reasons', 'مسودة سبب');
    }

    /**
     * The server-side effect of "per-file errors on their own row": a refused
     * file at index 1 leaves index 0's own error keys absent, so the client
     * can distinguish "this row failed" from "the whole submission failed".
     */
    public function test_a_rejected_attachment_reports_its_own_index_and_leaves_its_sibling_alone(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $employee = $this->userWithRole('R01');
        $department = Department::where('code', 'ADM')->firstOrFail();
        $type = RequestType::where('code', 'ALLW')->firstOrFail();
        $mandatoryKey = $this->mandatoryKeyFor($type);

        $this->actingAs($employee, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب بمرفق واحد صالح وآخر غير صالح',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
                'attachments' => [
                    [
                        'file' => UploadedFile::fake()->create('valid.pdf', 20, 'application/pdf'),
                        'required_document_key' => $mandatoryKey,
                    ],
                    [
                        'file' => UploadedFile::fake()->create('invalid.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                        'required_document_key' => 'other',
                    ],
                ],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors('attachments.0.file')
            ->assertJsonValidationErrors('attachments.1.file');

        $this->assertSame(0, Request::count());
    }

    /** The single mandatory [D] Appendix 57 row a type's own matrix names. */
    private function mandatoryKeyFor(RequestType $type): string
    {
        $keys = array_keys(app(DocumentCompletenessService::class)->mandatoryDocuments($type));

        return $keys[0];
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
