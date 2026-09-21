<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\DocumentCompletenessService;
use App\Services\EmploymentFilePreparationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\PassesControlGates;
use Tests\TestCase;

/**
 * Stage 98 — [D] Appendix 6 row 3: الموارد البشرية مسؤول for تجهيز الملف
 * الوظيفي, and until this stage the submitter did it.
 *
 * The row's own Done-when is "the party the matrix holds accountable is the
 * party that acts", so the two halves this file has to prove are that the
 * employee is no longer asked for الملف الوظيفي and that HR now is — and,
 * separately, that nothing was actually dropped on the way: the agenda gate
 * still demands every mandatory row, it is simply assembled by two parties at
 * two moments.
 */
class EmploymentFilePreparationTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    // --- the duty leaves the submitter --------------------------------

    public function test_intake_no_longer_asks_the_employee_for_the_employment_file(): void
    {
        $type = RequestType::where('code', 'PROM')->firstOrFail();
        $completeness = app(DocumentCompletenessService::class);

        $serviceFileKeys = array_keys(array_diff_key(
            $completeness->mandatoryDocuments($type),
            $completeness->mandatorySubmitterDocuments($type),
        ));

        $this->assertNotEmpty(
            $serviceFileKeys,
            'the seeded matrix should hold at least one unconditional الملف الوظيفي row',
        );

        // Every mandatory row EXCEPT the employment file — which is exactly
        // what an employee can now be expected to supply.
        $submitted = array_keys($completeness->mandatorySubmitterDocuments($type));

        $this->assertNull($completeness->refusalForSubmission($type, $submitted));

        // Dropping one of the submitter's own rows still refuses, so the rule
        // was narrowed rather than switched off.
        array_pop($submitted);
        $this->assertNotNull($completeness->refusalForSubmission($type, $submitted));
    }

    public function test_the_agenda_gate_still_demands_the_employment_file_rows(): void
    {
        $requestRecord = $this->requestBeingPrepared();

        // Only the submitter's own rows are attached, which intake now
        // accepts — the committee-facing check does not.
        foreach (array_keys(app(DocumentCompletenessService::class)->mandatorySubmitterDocuments($requestRecord->requestType)) as $key) {
            $this->attachDocument($requestRecord, $key);
        }

        $this->assertNotNull(app(DocumentCompletenessService::class)->refusalForRequest($requestRecord->fresh()));
    }

    // --- and lands on HR ----------------------------------------------

    public function test_registration_is_refused_until_hr_prepares_the_employment_file(): void
    {
        $requestRecord = $this->requestBeingPrepared();

        $this->actingAs($this->userWithRole('R12'), 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", ['action' => 'register'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.action.0',
                'لا يجوز تسجيل المعاملة قبل تجهيز الملف الوظيفي للموظف من قبل إدارة الموارد البشرية.',
            );

        $this->assertSame('receive_and_register', $requestRecord->fresh()->currentStage->code);
    }

    public function test_hr_prepares_the_file_and_then_registers_it(): void
    {
        $hr = $this->userWithRole('R12');
        $requestRecord = $this->requestBeingPrepared();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => $this->allPresent($requestRecord),
                'assembled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.control_gates.employment_file.refusal', null)
            ->assertJsonPath('data.control_gates.employment_file.prepared_by.id', $hr->id);

        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", ['action' => 'register'])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'requirements_check');
    }

    public function test_a_missing_row_refuses_and_not_applicable_only_where_the_source_conditions_it(): void
    {
        $hr = $this->userWithRole('R12');
        $requestRecord = $this->requestBeingPrepared();

        $documents = app(EmploymentFilePreparationService::class)->requiredDocuments($requestRecord);
        $unconditional = $this->firstKeyWhere($documents, false);
        $conditional = $this->firstKeyWhere($documents, true);

        $this->assertNotNull($unconditional, 'الملف الوظيفي should carry an unconditional row');
        $this->assertNotNull($conditional, 'الملف الوظيفي should carry a conditional row');

        $answers = $this->allPresent($requestRecord);

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => [...$answers, $unconditional => 'missing'],
                'assembled' => true,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.control_gates.employment_file.refusal',
                'لا يجوز التسجيل قبل استكمال الملف الوظيفي. المستند الناقص: '.$documents[$unconditional]['ar'],
            );

        // Appendix 57's own qualifier decides which may be waived.
        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => [...$answers, $unconditional => 'not_applicable'],
                'assembled' => true,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.control_gates.employment_file.refusal',
                'هذا المستند مطلوب في جميع الحالات ولا يجوز اعتباره غير منطبق: '.$documents[$unconditional]['ar'],
            );

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => [...$answers, $conditional => 'not_applicable'],
                'assembled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.control_gates.employment_file.refusal', null);
    }

    public function test_the_attestation_is_required_on_top_of_the_documents(): void
    {
        $requestRecord = $this->requestBeingPrepared();

        $this->actingAs($this->userWithRole('R12'), 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => $this->allPresent($requestRecord),
                'assembled' => false,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.control_gates.employment_file.refusal',
                'لا يجوز التسجيل قبل إثبات تجهيز الملف الوظيفي.',
            );
    }

    /**
     * Stage 78's own precedence, reused: a document in the file outranks
     * whatever anyone recorded about it, in both directions.
     */
    public function test_an_attached_document_overrides_a_recorded_missing(): void
    {
        $hr = $this->userWithRole('R12');
        $requestRecord = $this->requestBeingPrepared();

        $documents = app(EmploymentFilePreparationService::class)->requiredDocuments($requestRecord);
        $unconditional = $this->firstKeyWhere($documents, false);
        $this->attachDocument($requestRecord, $unconditional);

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => [...$this->allPresent($requestRecord), $unconditional => 'missing'],
                'assembled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.control_gates.employment_file.refusal', null);

        $stored = $requestRecord->fresh()->employment_file;
        $this->assertSame('present', $stored['documents'][$unconditional]);
        $this->assertTrue(
            collect($stored['items'])->firstWhere('key', $unconditional)['covered'],
            'a proven answer should be distinguishable from an attested one',
        );
    }

    // --- who may act --------------------------------------------------

    public function test_neither_the_filer_nor_the_subject_may_prepare_their_own_employment_file(): void
    {
        // R12 so the screen grant passes and the refusal under test is the
        // interested-party one rather than a 403 — row 3 gives الموظف a
        // literal `—`, whichever role they happen to hold.
        $filer = $this->userWithRole('R12');
        $requestRecord = $this->requestBeingPrepared(creator: $filer);

        $this->actingAs($filer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => $this->allPresent($requestRecord),
                'assembled' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يجوز لمقدّم الطلب أو صاحب العلاقة تجهيز ملفه الوظيفي بنفسه.');

        $subject = $this->userWithRole('R12');
        $requestRecord->forceFill(['subject_user_id' => $subject->id])->save();

        $this->actingAs($subject, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => $this->allPresent($requestRecord),
                'assembled' => true,
            ])
            ->assertStatus(422);

        $this->assertNull($requestRecord->fresh()->employment_file);
    }

    public function test_a_role_without_the_notes_grant_is_refused(): void
    {
        // R10 holds no notes_attachments grant at all. Stage 100 gave R06/R07 `notes_attachments,add` (Appendix 6 row 13's supervision), so R10 — a retained login with no duty since Stage 96 — is the role that holds no such grant.
        $requestRecord = $this->requestBeingPrepared();

        $this->actingAs($this->userWithRole('R10'), 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => $this->allPresent($requestRecord),
                'assembled' => true,
            ])
            ->assertStatus(403);
    }

    /**
     * Appendix 6 row 3's «مشارك». The manager holds a manager-gated row at the
     * two stages before this one and none at this one, so without the Stage 98
     * clause the file drops out of their sight at exactly the moment they are
     * meant to be contributing to it.
     */
    public function test_the_subjects_manager_can_open_the_file_while_hr_assembles_it(): void
    {
        $manager = $this->userWithRole('R02');
        $requestRecord = $this->requestBeingPrepared();

        $subject = $requestRecord->subject_user_id
            ? User::findOrFail($requestRecord->subject_user_id)
            : User::findOrFail($requestRecord->created_by_user_id);
        $subject->forceFill(['manager_id' => $manager->id])->save();

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();

        // And only while it sits there: the window is one stage wide.
        $requestRecord->forceFill([
            'current_stage_id' => WorkflowStage::where('code', 'reviewer_review')->value('id'),
        ])->save();

        $stranger = $this->userWithRole('R07');

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertStatus(404);
    }

    // --- fixtures -----------------------------------------------------

    private function requestBeingPrepared(?User $creator = null): Request
    {
        $creator ??= $this->userWithRole('R01');

        return Request::create([
            'reference_number' => null,
            'intake_receipt_number' => 'PM-RCV/2026/'.str_pad((string) (Request::count() + 1), 6, '0', STR_PAD_LEFT),
            'title' => 'طلب عند تجهيز الملف الوظيفي',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'routed_to_hr')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_and_register')->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now(),
            'decision_grade' => 10,
        ]);
    }

    /** @return array<string, string> */
    private function allPresent(Request $requestRecord): array
    {
        return array_fill_keys(
            array_keys(app(EmploymentFilePreparationService::class)->requiredDocuments($requestRecord)),
            'present',
        );
    }

    /** @param  array<string, array{conditional: bool}>  $documents */
    private function firstKeyWhere(array $documents, bool $conditional): ?string
    {
        foreach ($documents as $key => $document) {
            if ($document['conditional'] === $conditional) {
                return $key;
            }
        }

        return null;
    }

    private function attachDocument(Request $requestRecord, string $key): void
    {
        Attachment::create([
            'request_id' => $requestRecord->id,
            'disk' => 'local',
            'path' => UploadedFile::fake()->create("{$key}.pdf", 10, 'application/pdf')
                ->store("attachments/{$requestRecord->id}", 'local'),
            'original_name' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'required_document_key' => $key,
            'file_section' => $requestRecord->requestType->sectionForDocument($key),
            'uploaded_by_user_id' => $requestRecord->created_by_user_id,
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
