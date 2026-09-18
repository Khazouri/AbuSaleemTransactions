<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestDraft;
use App\Models\RequestDraftAttachment;
use App\Models\RequestStageLog;
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
 * Stage 88 — an intake can be put down and picked up, and read before it is
 * sent.
 *
 * The load-bearing half of this stage is negative, so most of what is asserted
 * here is what a draft ISN'T: no receipt, no stage log, no status, invisible
 * to the request list and to every register. Those hold because a draft is its
 * own table rather than a `requests` row — these tests are what would notice
 * if a later stage moved it there.
 */
class RequestDraftTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;

    public function test_a_draft_keeps_what_was_typed_and_can_be_picked_back_up(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        $type = RequestType::where('code', 'ALLW')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();

        $created = $this->actingAs($employee, 'sanctum')
            ->postJson('/api/requests/drafts', ['title' => 'بدل طبيعة عمل'])
            ->assertCreated()
            ->json('data');

        // Half-filled on purpose: a draft that required a department or a type
        // would defeat the one thing it exists for.
        $this->assertSame('بدل طبيعة عمل', $created['payload']['title']);

        $this->actingAs($employee, 'sanctum')
            ->putJson("/api/requests/drafts/{$created['id']}", [
                'title' => 'بدل طبيعة عمل — محدث',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
            ])
            ->assertOk();

        // The refresh this stage exists for: a separate request, nothing in
        // memory, everything still there.
        $resumed = $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/drafts/{$created['id']}")
            ->assertOk()
            ->json('data');

        $this->assertSame('بدل طبيعة عمل — محدث', $resumed['payload']['title']);
        $this->assertSame($type->id, $resumed['payload']['request_type_id']);

        // A replace, not a merge — a field the employee cleared has to come
        // back cleared, or emptying one would be impossible to save.
        $this->actingAs($employee, 'sanctum')
            ->putJson("/api/requests/drafts/{$created['id']}", ['title' => 'بدل طبيعة عمل — محدث'])
            ->assertOk()
            ->assertJsonPath('data.payload.request_type_id', null);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/requests/drafts')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * The stage's own load-bearing rule, asserted directly.
     *
     * A draft takes no `intake_receipt_number`, writes no stage log, and
     * appears in no workflow queue or register — because it is not a request
     * at all. If a later stage moves drafts onto `requests`, this is what
     * fails.
     */
    public function test_a_draft_is_not_a_request(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/requests/drafts', ['title' => 'مسودة لم ترسل'])
            ->assertCreated();

        $this->assertSame(0, Request::count());
        $this->assertSame(0, RequestStageLog::count());

        // The internal work queue and the Art. 98 register of incoming
        // requests both read `requests`, so neither can see it.
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/requests')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/registers/incoming')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_another_employees_draft_is_not_reachable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $owner = $this->userWithRole('R01');
        $stranger = $this->userWithRole('R02');

        $draft = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/requests/drafts', ['title' => 'خاص'])
            ->assertCreated()
            ->json('data.id');

        // 404 rather than 403 throughout: a 403 would confirm that another
        // employee's draft exists.
        $this->actingAs($stranger, 'sanctum')->getJson("/api/requests/drafts/{$draft}")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->putJson("/api/requests/drafts/{$draft}", ['title' => 'x'])->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->deleteJson("/api/requests/drafts/{$draft}")->assertNotFound();

        // And it is not merely hidden from the one endpoint: the list is the
        // caller's own drafts only.
        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/requests/drafts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Drafts are an ADDED capability, not a new requirement.
     *
     * They ride `request_intake,edit`, which R03/R04/R06 do not hold — so this
     * asserts both halves: the draft routes are refused, and filing an intake
     * still works exactly as it did before this stage.
     */
    public function test_a_role_without_the_intake_edit_grant_cannot_draft_but_can_still_file(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $chair = $this->userWithRole('R03');
        $type = RequestType::where('code', 'ALLW')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();

        $this->actingAs($chair, 'sanctum')
            ->getJson('/api/requests/drafts')
            ->assertForbidden();

        $this->actingAs($chair, 'sanctum')
            ->postJson('/api/requests/drafts', ['title' => 'مسودة'])
            ->assertForbidden();

        $this->actingAs($chair, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب بدل',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
                'attachments' => $this->mandatoryAttachments($type),
            ], ['Accept' => 'application/json'])
            ->assertCreated();
    }

    public function test_a_drafts_files_are_promoted_into_the_request_at_submission(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $employee = $this->userWithRole('R01');
        $type = RequestType::where('code', 'ALLW')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();

        $draft = $this->draftFor($employee);
        $mandatoryKey = array_key_first(
            app(DocumentCompletenessService::class)->mandatoryDocuments($type),
        );

        $attachment = $this->actingAs($employee, 'sanctum')
            ->post("/api/requests/drafts/{$draft->id}/attachments", [
                'file' => UploadedFile::fake()->create('employee-request.pdf', 40, 'application/pdf'),
                'label' => 'طلب الموظف',
                'required_document_key' => $mandatoryKey,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data');

        $draftPath = RequestDraftAttachment::findOrFail($attachment['id'])->path;
        Storage::disk('local')->assertExists($draftPath);

        $response = $this->actingAs($employee, 'sanctum')
            ->postJson('/api/requests', [
                'draft_id' => $draft->id,
                'title' => 'طلب بدل طبيعة عمل',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
            ])
            ->assertCreated();

        $requestRecord = Request::findOrFail($response->json('data.id'));
        $promoted = $requestRecord->attachments()->sole();

        // The row is identical to one an inline upload would have written —
        // same key, and the [D] Appendix 14 folder derived from it rather than
        // carried over from the draft.
        $this->assertSame('طلب الموظف', $promoted->label);
        $this->assertSame($mandatoryKey, $promoted->required_document_key);
        $this->assertSame($type->sectionForDocument($mandatoryKey), $promoted->file_section);
        $this->assertSame($employee->id, $promoted->uploaded_by_user_id);
        Storage::disk('local')->assertExists($promoted->path);
        $this->assertStringStartsWith("attachments/{$requestRecord->id}/", $promoted->path);

        // The draft has served its purpose: row and stored file both gone.
        $this->assertNull(RequestDraft::find($draft->id));
        $this->assertSame(0, RequestDraftAttachment::count());
        Storage::disk('local')->assertMissing($draftPath);

        // And the file itself is filed as an ordinary intake: receipt, first
        // stage log, the system hop into direct_manager_review.
        $this->assertNotNull($requestRecord->intake_receipt_number);
        $this->assertNull($requestRecord->reference_number);
        $this->assertSame('direct_manager_review', $requestRecord->currentStage->code);

        // The review step showed the employee which Appendix 57 row each file
        // declares, so the workspace they land in has to agree. It did not:
        // detailResource()'s restricted eager load omitted both columns, so
        // AttachmentResource reported them as null for every request since the
        // stages that added them. Found by this stage's own smoke run, and
        // pinned here because a column dropped from that list fails silently.
        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.attachments.0.required_document_key', $mandatoryKey)
            ->assertJsonPath('data.attachments.0.file_section', $type->sectionForDocument($mandatoryKey));
    }

    public function test_inline_attachments_are_refused_alongside_a_draft(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $employee = $this->userWithRole('R01');
        $type = RequestType::where('code', 'ALLW')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();
        $draft = $this->draftFor($employee);

        $this->actingAs($employee, 'sanctum')
            ->post('/api/requests', [
                'draft_id' => $draft->id,
                'title' => 'طلب بدل',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
                'attachments' => $this->mandatoryAttachments($type),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments');

        $this->assertSame(0, Request::count());
    }

    /**
     * Stage 85's completeness rule reads the DRAFT's own files.
     *
     * Reading the payload alone would report every mandatory row as uncovered
     * and refuse a complete file — so this is what keeps one completeness bar
     * rather than a softer one for drafts.
     */
    public function test_appendix_57_completeness_is_enforced_against_the_drafts_own_files(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $employee = $this->userWithRole('R01');
        $type = RequestType::where('code', 'ALLW')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();
        $draft = $this->draftFor($employee);

        $payload = [
            'draft_id' => $draft->id,
            'title' => 'طلب بدل',
            'department_id' => $department->id,
            'request_type_id' => $type->id,
            'decision_grade' => 11,
        ];

        $refusal = $this->actingAs($employee, 'sanctum')
            ->postJson('/api/requests', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments')
            ->json('errors.attachments.0');

        // [G]'s own wording, which this system could not produce before
        // Stage 85 and could not produce for a draft before this one.
        $this->assertStringContainsString('الرجاء إرفاق المستندات المطلوبة', $refusal);
        $this->assertSame(0, Request::count());

        $mandatoryKey = array_key_first(
            app(DocumentCompletenessService::class)->mandatoryDocuments($type),
        );

        $this->actingAs($employee, 'sanctum')
            ->post("/api/requests/drafts/{$draft->id}/attachments", [
                'file' => UploadedFile::fake()->create('covered.pdf', 20, 'application/pdf'),
                'required_document_key' => $mandatoryKey,
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/requests', $payload)
            ->assertCreated();
    }

    /**
     * A draft accepts a document key without checking it against a matrix —
     * its type can still change while it is being typed — so the check has to
     * land at submission, against the type actually being filed.
     */
    public function test_a_draft_file_must_be_classified_for_the_type_being_filed(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $employee = $this->userWithRole('R01');
        $type = RequestType::where('code', 'ALLW')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();

        // A key from a DIFFERENT type's own list. The nine shared basics
        // produce identical keys across every type, so only a row from this
        // other type's own file is genuinely foreign — picked by difference,
        // the way Stage 91's note records finding out the hard way.
        $otherType = RequestType::where('code', 'TRNS')->firstOrFail();
        $foreignKey = array_key_first(array_diff_key($otherType->documentOptions(), $type->documentOptions()));
        $this->assertNotNull($foreignKey);

        $draft = $this->draftFor($employee);
        $this->actingAs($employee, 'sanctum')
            ->post("/api/requests/drafts/{$draft->id}/attachments", [
                'file' => UploadedFile::fake()->create('foreign.pdf', 20, 'application/pdf'),
                'required_document_key' => $foreignKey,
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $payload = [
            'draft_id' => $draft->id,
            'title' => 'طلب بدل',
            'department_id' => $department->id,
            'request_type_id' => $type->id,
            'decision_grade' => 11,
        ];

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/requests', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments');

        // An unanswered file is refused too — a draft-backed submission must
        // not become the one way to file an unclassified document.
        $unansweredDraft = $this->draftFor($employee);
        $this->actingAs($employee, 'sanctum')
            ->post("/api/requests/drafts/{$unansweredDraft->id}/attachments", [
                'file' => UploadedFile::fake()->create('unanswered.pdf', 20, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/requests', [...$payload, 'draft_id' => $unansweredDraft->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments');

        $this->assertSame(0, Request::count());
        $this->assertSame(0, Attachment::count());
    }

    /**
     * A refused submission has to leave the draft resumable.
     *
     * Until the transaction commits, the draft's copy is the only one the
     * employee could pick back up — which is why the files are copied rather
     * than moved, and why the draft is deleted only afterwards.
     */
    public function test_a_refused_submission_leaves_the_draft_and_its_file_intact(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $employee = $this->userWithRole('R01');
        $type = RequestType::where('code', 'ALLW')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();
        $draft = $this->draftFor($employee);
        $mandatoryKey = array_key_first(
            app(DocumentCompletenessService::class)->mandatoryDocuments($type),
        );

        $this->actingAs($employee, 'sanctum')
            ->post("/api/requests/drafts/{$draft->id}/attachments", [
                'file' => UploadedFile::fake()->create('kept.pdf', 20, 'application/pdf'),
                'required_document_key' => $mandatoryKey,
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $draftPath = RequestDraftAttachment::query()->sole()->path;

        // Refused on the title, i.e. before anything is written at all.
        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/requests', [
                'draft_id' => $draft->id,
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');

        $this->assertNotNull(RequestDraft::find($draft->id));
        Storage::disk('local')->assertExists($draftPath);

        // And it really is still submittable afterwards.
        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/requests', [
                'draft_id' => $draft->id,
                'title' => 'طلب بدل',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
            ])
            ->assertCreated();
    }

    public function test_a_draft_file_can_be_reclassified_removed_and_previewed_by_its_author_only(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $employee = $this->userWithRole('R01');
        $stranger = $this->userWithRole('R02');
        $type = RequestType::where('code', 'ALLW')->firstOrFail();
        $draft = $this->draftFor($employee);

        $attachment = $this->actingAs($employee, 'sanctum')
            ->post("/api/requests/drafts/{$draft->id}/attachments", [
                'file' => UploadedFile::fake()->create('note.pdf', 20, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data');

        $this->assertNull($attachment['required_document_key']);

        // The review step reads the file back, so it needs a private stream —
        // the author's alone.
        $this->actingAs($employee, 'sanctum')
            ->get("/api/requests/drafts/{$draft->id}/attachments/{$attachment['id']}/preview")
            ->assertOk();

        $this->actingAs($stranger, 'sanctum')
            ->get("/api/requests/drafts/{$draft->id}/attachments/{$attachment['id']}/preview")
            ->assertNotFound();

        // A VALID payload deliberately: FormRequest validation runs before the
        // controller's ownership check, so an empty body would 422 first and
        // this would pass without ever exercising the refusal it is about.
        $this->actingAs($stranger, 'sanctum')
            ->post("/api/requests/drafts/{$draft->id}/attachments", [
                'file' => UploadedFile::fake()->create('intruder.pdf', 20, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertNotFound();

        $key = array_key_first($type->documentOptions());
        $this->actingAs($employee, 'sanctum')
            ->patchJson("/api/requests/drafts/{$draft->id}/attachments/{$attachment['id']}", [
                'label' => 'مرفق مصنف',
                'required_document_key' => $key,
            ])
            ->assertOk()
            ->assertJsonPath('data.required_document_key', $key);

        $path = RequestDraftAttachment::findOrFail($attachment['id'])->path;

        $this->actingAs($employee, 'sanctum')
            ->deleteJson("/api/requests/drafts/{$draft->id}/attachments/{$attachment['id']}")
            ->assertNoContent();

        $this->assertSame(0, RequestDraftAttachment::count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_discarding_a_draft_removes_its_stored_files(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $employee = $this->userWithRole('R01');
        $draft = $this->draftFor($employee);

        $this->actingAs($employee, 'sanctum')
            ->post("/api/requests/drafts/{$draft->id}/attachments", [
                'file' => UploadedFile::fake()->create('discarded.pdf', 20, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $path = RequestDraftAttachment::query()->sole()->path;

        $this->actingAs($employee, 'sanctum')
            ->deleteJson("/api/requests/drafts/{$draft->id}")
            ->assertNoContent();

        $this->assertSame(0, RequestDraft::count());
        // Cascade, so the child rows go with it — and the private directory
        // goes too, rather than leaving orphaned bytes behind.
        $this->assertSame(0, RequestDraftAttachment::count());
        Storage::disk('local')->assertMissing($path);
    }

    private function draftFor(User $employee): RequestDraft
    {
        return RequestDraft::create([
            'created_by_user_id' => $employee->id,
            'payload' => [],
        ]);
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
