<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\DocumentCompletenessService;
use App\Services\IntakeGateService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stage 91 — one document vocabulary, whenever a document is attached.
 *
 * Until now `AttachmentController::store()` asked [D] Appendix 14's raw folder
 * question and nothing else, so a document supplied during استكمال النواقص
 * ([F] step 5) named no Appendix 57 row — and the officer's completeness gate,
 * which reads exactly that column, could not see it. The consequence Stage 85
 * recorded was real: an incomplete file could not be completed through the
 * ordinary upload path at all, only by refiling.
 *
 * The question is NOT branched by who uploads. Art. 19's loop sends an
 * incomplete file back to requirements_check, where R02-R05 routinely attach
 * on the employee's behalf, so a submitter-only question would have left staff
 * unable to close the very gap this closes — which is why the end-to-end case
 * below is walked by the officer, not by the employee.
 */
class CompletionDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    // --- the question -----------------------------------------------------

    public function test_an_upload_must_name_the_document_it_answers(): void
    {
        $requestRecord = $this->requestAtRequirementsCheck();

        $this->actingAs($requestRecord->createdBy, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
                'file_section' => 'supporting_documents',
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('required_document_key');

        // A refused upload leaves neither a row nor a private file behind.
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /**
     * The key is resolved from THIS request's own type, so a slug that is
     * real — just for another type's matrix — is refused rather than stored as
     * an answer to a question this file was never asked.
     *
     * It has to be a type-SPECIFIC row: documentKey() is {index}-{sha1(ar)},
     * and Appendix 57's nine shared basics are merged into every type at the
     * same positions, so those keys are legitimately identical across types.
     * Only a row from a type's own ملف is genuinely foreign.
     */
    public function test_a_key_belonging_to_another_type_is_refused(): void
    {
        $requestRecord = $this->requestAtRequirementsCheck();
        $ownKeys = $requestRecord->requestType->documentOptions();
        $foreignKey = array_key_first(array_diff_key(
            RequestType::where('code', 'TRNS')->firstOrFail()->documentOptions(),
            $ownKeys,
        ));
        $this->assertNotNull($foreignKey);

        $this->actingAs($requestRecord->createdBy, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
                'required_document_key' => $foreignKey,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('required_document_key');

        $this->assertDatabaseCount('attachments', 0);
    }

    // --- the folder -------------------------------------------------------

    /**
     * A named row already declares its folder, so the folder is derived — and
     * a submitted one is overridden rather than trusted, the same discipline
     * IntakeGateService::derivedAnswers() applies to a spoofed `missing`.
     */
    public function test_a_named_row_derives_its_own_folder_over_a_submitted_one(): void
    {
        $requestRecord = $this->requestAtRequirementsCheck();
        [$key, $option] = $this->optionWithDeclaredSection($requestRecord->requestType);

        $this->actingAs($requestRecord->createdBy, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
                'required_document_key' => $key,
                // Nothing the uploader can send changes where a declared row files.
                'file_section' => 'closure',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.required_document_key', $key)
            ->assertJsonPath('data.file_section', $option['section']);

        $this->assertNotSame('closure', Attachment::first()->file_section);
    }

    /** `other` is a real answer, and the only one that leaves a folder unstated. */
    public function test_other_is_an_answer_and_then_the_folder_is_a_real_question(): void
    {
        $requestRecord = $this->requestAtRequirementsCheck();

        $this->actingAs($requestRecord->createdBy, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
                'required_document_key' => 'other',
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file_section');

        // The full twelve stay reachable here, not the submitter's three:
        // R02-R05 file genuine committee-cycle documents through this endpoint.
        $this->actingAs($requestRecord->createdBy, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
                'required_document_key' => 'other',
                'file_section' => 'presentation_memo',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.required_document_key', 'other')
            ->assertJsonPath('data.file_section', 'presentation_memo');
    }

    // --- the loop this stage exists to close ------------------------------

    /**
     * The stage's own done-when: a document supplied AFTER intake is read by
     * the officer's gate exactly as one supplied with it.
     *
     * Walked by the filer: only they attach documents now
     * (Request::attachmentRight()), so the officer who finds the gap is
     * refused and the file goes back to the employee — which is the loop
     * Art. 19's استكمال actually describes.
     */
    public function test_a_document_supplied_after_intake_closes_the_gap_it_left(): void
    {
        [$head, $meeting] = $this->committeeMeeting();
        $requestRecord = $this->presentableRequest();
        $missingKey = $this->supplyAllMandatoryDocumentsBarOne($requestRecord);

        // The gap is real: the agenda refuses the file, and the officer's gate
        // has no derived answer for the row nothing covers.
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestRecord->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('request_id');

        $gate = app(IntakeGateService::class);
        $this->assertArrayNotHasKey($missingKey, $gate->derivedAnswers($requestRecord->fresh()));

        // The officer who found the gap may not fill it themselves...
        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('missing.pdf', 10, 'application/pdf'),
                'required_document_key' => $missingKey,
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        // ...the filer does. Before Stage 91 this upload could not name the
        // row at all, so the gap could only be closed by refiling.
        $this->actingAs($requestRecord->createdBy, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('missing.pdf', 10, 'application/pdf'),
                'required_document_key' => $missingKey,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.required_document_key', $missingKey);

        $fresh = $requestRecord->fresh();
        $this->assertSame('present', $gate->derivedAnswers($fresh)[$missingKey] ?? null);
        $this->assertNull(app(DocumentCompletenessService::class)->refusalForRequest($fresh));

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestRecord->id])
            ->assertCreated();
    }

    // --- what the form is told --------------------------------------------

    public function test_the_upload_form_is_told_which_rows_are_covered_and_which_are_outstanding(): void
    {
        $requestRecord = $this->presentableRequest();
        $missingKey = $this->supplyAllMandatoryDocumentsBarOne($requestRecord);

        $response = $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}/document-options")
            ->assertOk();

        $keys = array_column($response->json('data.document_options'), 'key');
        $this->assertContains($missingKey, $keys);
        // The same keyed matrix intake renders, carrying its derived folder.
        $this->assertNotEmpty($response->json('data.document_options.0.section'));

        $this->assertNotContains($missingKey, $response->json('data.covered_keys'));
        $this->assertSame([$missingKey], array_column($response->json('data.outstanding'), 'key'));
    }

    public function test_the_lookup_needs_the_same_grant_as_the_upload_it_renders(): void
    {
        $requestRecord = $this->presentableRequest();

        // R10 holds no notes_attachments,add. Stage 100 gave R06/R07 `notes_attachments,add` (Appendix 6 row 13's supervision), so R10 — a retained login with no duty since Stage 96 — is the role that holds no such grant.
        $this->actingAs($this->userWithRole('R10'), 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}/document-options")
            ->assertForbidden();
    }

    // --- fixtures ---------------------------------------------------------

    /**
     * Covers every mandatory row but the last, and returns the key it left —
     * the state Stage 85's submission rule now prevents at intake but which a
     * legacy file, or a matrix that gained a row through the Request Types
     * screen, still reaches.
     */
    private function supplyAllMandatoryDocumentsBarOne(Request $requestRecord): string
    {
        $keys = array_keys(
            app(DocumentCompletenessService::class)->mandatoryDocuments($requestRecord->requestType),
        );
        $missing = array_pop($keys);

        foreach ($keys as $index => $key) {
            Attachment::create([
                'request_id' => $requestRecord->id,
                'disk' => 'local',
                'path' => "attachments/{$requestRecord->id}/fixture-{$index}.pdf",
                'original_name' => "fixture-{$index}.pdf",
                'mime_type' => 'application/pdf',
                'size_bytes' => 1024,
                'required_document_key' => $key,
                'file_section' => $requestRecord->requestType->sectionForDocument($key),
                'uploaded_by_user_id' => $requestRecord->created_by_user_id,
            ]);
        }

        $requestRecord->unsetRelation('attachments');

        return $missing;
    }

    /** The first row whose seeder declares a folder other than the default. */
    private function optionWithDeclaredSection(RequestType $type): array
    {
        foreach ($type->documentOptions() as $key => $option) {
            if ($option['section'] !== Attachment::DEFAULT_SUBMITTER_SECTION) {
                return [$key, $option];
            }
        }

        $this->fail('No seeded row declares its own Appendix 14 folder.');
    }

    private function committeeMeeting(): array
    {
        $head = $this->userWithRole('R03');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين', 'is_active' => true]);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'meeting_number' => 'PM-MTG/2026/01',
            'title' => 'اجتماع دوري',
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
            'created_by_user_id' => $head->id,
        ]);

        return [$head, $meeting];
    }

    /** A file that clears every agenda gate upstream of the document one. */
    private function presentableRequest(): Request
    {
        $requestRecord = Request::create([
            'reference_number' => 'PM-COM/2026/0001',
            'title' => 'طلب جاهز للعرض',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'ready')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $this->userWithRole('R01')->id,
            'submitted_at' => now(),
        ]);

        // Stage 68's gate is upstream of this one, so a fixture about documents
        // has to clear the legal review first.
        $requestRecord->legalReviews()->create([
            'verdict' => 'sound_ready',
            'committee_mandate' => 'decision',
            'reviewed_at' => now(),
        ]);

        return $requestRecord->refresh();
    }

    private function requestAtRequirementsCheck(): Request
    {
        return Request::create([
            'intake_receipt_number' => 'PM-RCV/2026/000001',
            'title' => 'طلب عند فحص الاكتمال',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_review')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'requirements_check')->value('id'),
            'created_by_user_id' => $this->userWithRole('R01')->id,
            'submitted_at' => now(),
            'decision_grade' => 10,
        ])->refresh();
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
