<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AttachmentPreview;
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
        $requestRecord = $this->requestBeingPrepared(documented: false);

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
                'documents' => $this->approveRequiredFiles($requestRecord, $hr),
                'assembled' => true,
                'grade_verified' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.control_gates.employment_file.refusal', null)
            ->assertJsonPath('data.control_gates.employment_file.prepared_by.id', $hr->id);

        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", ['action' => 'register'])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'requirements_check');
    }

    public function test_the_attestation_is_required_on_top_of_the_documents(): void
    {
        $hr = $this->userWithRole('R12');
        $requestRecord = $this->requestBeingPrepared();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => $this->approveRequiredFiles($requestRecord, $hr),
                'assembled' => false,
                'grade_verified' => true,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.control_gates.employment_file.refusal',
                'لا يجوز التسجيل قبل إثبات تجهيز الملف الوظيفي.',
            );
    }

    public function test_hr_must_confirm_the_grade_data_the_employee_stated(): void
    {
        $hr = $this->userWithRole('R12');
        $requestRecord = $this->requestBeingPrepared();
        $verdicts = $this->approveRequiredFiles($requestRecord, $hr);

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => $verdicts,
                'assembled' => true,
                'grade_verified' => false,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.control_gates.employment_file.refusal',
                'لا يجوز التسجيل قبل تأكد الموارد البشرية من مطابقة الدرجة الوظيفية والدرجة المستحقة وتاريخ الدرجة لسجلات الجهة.',
            )
            ->assertJsonPath('data.entitled_grade', 11)
            ->assertJsonPath('data.grade_date', '2024-03-01');

        // The confirmation is frozen beside the values it confirmed, so a
        // later reader sees what HR actually checked.
        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => $verdicts,
                'assembled' => true,
                'grade_verified' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.control_gates.employment_file.refusal', null)
            ->assertJsonPath('data.control_gates.employment_file.record.grade', [
                'decision_grade' => 10,
                'entitled_grade' => 11,
                'grade_date' => '2024-03-01',
            ]);
    }

    /**
     * HR checks every required attachment: the box ticked on Request Types
     * holds registration until a file answers that row, whoever uploads it.
     */
    public function test_registration_waits_for_every_required_document(): void
    {
        $hr = $this->userWithRole('R12');
        $requestRecord = $this->requestBeingPrepared(documented: false);

        $refusal = $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => [],
                'assembled' => true,
                'grade_verified' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.control_gates.employment_file.missing_required.0.ar', 'البيانات الوظيفية')
            ->json('data.control_gates.employment_file.refusal');

        $this->assertStringStartsWith(EmploymentFilePreparationService::REQUIRED_MISSING, (string) $refusal);
        $this->assertStringContainsString('البيانات الوظيفية', (string) $refusal);

        $this->supplyRequiredDocuments($requestRecord);

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => $this->approveRequiredFiles($requestRecord, $hr),
                'assembled' => true,
                'grade_verified' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.control_gates.employment_file.refusal', null)
            ->assertJsonPath('data.control_gates.employment_file.missing_required', []);
    }

    /**
     * And only those: a file outside the required rows is not HR's to judge,
     * so it is neither listed on HR's card nor able to hold registration.
     */
    public function test_hr_reviews_only_the_files_answering_a_required_row(): void
    {
        $hr = $this->userWithRole('R12');
        $requestRecord = $this->requestBeingPrepared();
        $this->attachDocument($requestRecord, RequestType::OTHER_DOCUMENT);
        $other = Attachment::where('required_document_key', RequestType::OTHER_DOCUMENT)->value('id');

        $listed = array_column($this->actingAs($hr, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->json('data.control_gates.employment_file.review_attachments'), 'id');

        $this->assertCount(count($this->mandatoryKeys($requestRecord)), $listed);
        $this->assertNotContains($other, $listed);

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => $this->approveRequiredFiles($requestRecord, $hr),
                'assembled' => true,
                'grade_verified' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.control_gates.employment_file.refusal', null);
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
                'documents' => [],
                'assembled' => true,
                'grade_verified' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يجوز لمقدّم الطلب أو صاحب العلاقة تجهيز ملفه الوظيفي بنفسه.');

        $subject = $this->userWithRole('R12');
        $requestRecord->forceFill(['subject_user_id' => $subject->id])->save();

        $this->actingAs($subject, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => [],
                'assembled' => true,
                'grade_verified' => true,
            ])
            ->assertStatus(422);

        $this->assertNull($requestRecord->fresh()->employment_file);
    }

    public function test_a_role_without_the_notes_grant_is_refused(): void
    {
        // R09 holds no notes_attachments grant at all. Stage 100 gave R06/R07 `notes_attachments,add` (Appendix 6 row 13's supervision), so R09 — a retained login with no duty since Stage 96 — is the role that holds no such grant.
        $requestRecord = $this->requestBeingPrepared();

        $this->actingAs($this->userWithRole('R09'), 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                'documents' => [],
                'assembled' => true,
                'grade_verified' => true,
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

    /** Carries one file per required row unless told otherwise; DocumentReviewTest covers the verdicts themselves. */
    private function requestBeingPrepared(?User $creator = null, bool $documented = true): Request
    {
        $creator ??= $this->userWithRole('R01');

        $requestRecord = Request::create([
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
            'entitled_grade' => 11,
            'grade_date' => '2024-03-01',
        ]);

        if ($documented) {
            $this->supplyRequiredDocuments($requestRecord);
        }

        return $requestRecord;
    }

    /**
     * HR's approval of every required file, each one previewed first as the
     * endpoint demands.
     *
     * @return array<int, string>
     */
    private function approveRequiredFiles(Request $requestRecord, User $hr): array
    {
        $files = Attachment::where('request_id', $requestRecord->id)
            ->whereIn('required_document_key', $this->mandatoryKeys($requestRecord))
            ->get();

        foreach ($files as $file) {
            AttachmentPreview::firstOrCreate(['attachment_id' => $file->id, 'user_id' => $hr->id]);
        }

        return $files->mapWithKeys(fn (Attachment $file): array => [$file->id => 'approved'])->all();
    }

    /** @return list<string> */
    private function mandatoryKeys(Request $requestRecord): array
    {
        return array_keys(app(DocumentCompletenessService::class)->mandatoryDocuments($requestRecord->requestType));
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
