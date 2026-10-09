<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AttachmentPreview;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\DocumentReview;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Both document gates — HR's تجهيز الملف الوظيفي and المقرر's بوابة 1 — judge
 * the files actually attached, one verdict per file, and only after the
 * reviewer has opened that file's full preview, which the server records
 * itself (user decision 2026-10-09).
 */
class DocumentReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    // --- the preview event ----------------------------------------------

    public function test_opening_a_preview_is_recorded_and_audited(): void
    {
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review');
        $attachment = $this->attach($requestRecord, 'صورة القرار.pdf');

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/attachments/{$attachment->id}/previewed")
            ->assertNoContent();

        $this->assertTrue(AttachmentPreview::where([
            'attachment_id' => $attachment->id,
            'user_id' => $reviewer->id,
        ])->exists());
        $this->assertTrue(AuditLog::where('auditable_type', AttachmentPreview::class)
            ->where('user_id', $reviewer->id)
            ->where('action', 'created')
            ->exists());
    }

    public function test_a_preview_of_another_requests_file_or_an_unseen_request_is_a_404(): void
    {
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review');
        $other = $this->requestAt('requirements_check', 'in_review');
        $foreign = $this->attach($other, 'مستند.pdf');

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/attachments/{$foreign->id}/previewed")
            ->assertNotFound();

        // An employee who neither filed nor is the subject of the file.
        $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->postJson("/api/requests/{$other->id}/attachments/{$foreign->id}/previewed")
            ->assertNotFound();

        $this->assertSame(0, AttachmentPreview::count());
    }

    // --- بوابة 1 ---------------------------------------------------------

    public function test_a_verdict_on_a_file_the_reviewer_never_opened_is_refused(): void
    {
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review');
        $attachment = $this->attach($requestRecord, 'صورة القرار.pdf');

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/intake-gate", [
                'documents' => [$attachment->id => 'approved'],
                'facts_verified' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.documents.0', DocumentReview::NOT_PREVIEWED.'صورة القرار.pdf');

        $this->assertNull($requestRecord->fresh()->intake_gate);
    }

    public function test_every_attached_file_needs_a_verdict_and_the_gate_then_passes(): void
    {
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review');
        $first = $this->attach($requestRecord, 'الأول.pdf');
        $second = $this->attach($requestRecord, 'الثاني.pdf');
        $this->preview($reviewer, $first);
        $this->preview($reviewer, $second);

        $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.control_gates.intake.review_attachments', [
                ['id' => $first->id, 'previewed' => true],
                ['id' => $second->id, 'previewed' => true],
            ]);

        $this->recordIntake($reviewer, $requestRecord, [$first->id => 'approved'])
            ->assertJsonPath('data.control_gates.intake.refusal', DocumentReview::NOT_REVIEWED.'الثاني.pdf');

        $this->recordIntake($reviewer, $requestRecord, [$first->id => 'approved', $second->id => 'approved'])
            ->assertJsonPath('data.control_gates.intake.refusal', null)
            ->assertJsonPath('data.control_gates.intake.record.items.1.verdict', 'approved')
            ->assertJsonPath('data.control_gates.intake.record.items.1.name', 'الثاني.pdf');

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'registered');
    }

    public function test_a_rejected_file_blocks_until_a_newer_one_of_the_same_document_replaces_it(): void
    {
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review');
        $key = array_key_first($requestRecord->requestType->documentOptions());
        $rejected = $this->attach($requestRecord, 'نسخة غير واضحة.pdf', $key);
        $this->preview($reviewer, $rejected);

        $this->recordIntake($reviewer, $requestRecord, [$rejected->id => 'not_approved'])
            ->assertJsonPath('data.control_gates.intake.refusal', DocumentReview::NOT_APPROVED.'نسخة غير واضحة.pdf');

        $replacement = $this->attach($requestRecord, 'نسخة واضحة.pdf', $key);
        $this->preview($reviewer, $replacement);

        // The replacement alone is now under review; the rejected copy stays
        // on the file but no longer has a row of its own.
        $this->recordIntake($reviewer, $requestRecord, [$replacement->id => 'approved'])
            ->assertJsonPath('data.control_gates.intake.refusal', null)
            ->assertJsonCount(1, 'data.control_gates.intake.record.items');
    }

    public function test_a_file_added_after_the_review_reopens_it_and_unknown_ids_are_dropped(): void
    {
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review');
        $first = $this->attach($requestRecord, 'الأول.pdf');
        $this->preview($reviewer, $first);

        $this->recordIntake($reviewer, $requestRecord, [$first->id => 'approved', 999999 => 'approved'])
            ->assertJsonPath('data.control_gates.intake.refusal', null);

        $this->assertSame([(string) $first->id => 'approved'], $requestRecord->fresh()->intake_gate['documents']);

        $this->attach($requestRecord, 'متأخر.pdf');

        $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertJsonPath('data.control_gates.intake.refusal', DocumentReview::NOT_REVIEWED.'متأخر.pdf');
    }

    // --- تجهيز الملف الوظيفي ---------------------------------------------

    public function test_hr_judges_the_same_files_and_a_rejected_one_blocks_registration(): void
    {
        $hr = $this->userWithRole('R12');
        $requestRecord = $this->requestAt('receive_and_register', 'routed_to_hr');
        $attachment = $this->attach($requestRecord, 'بيان الخدمة.pdf');
        $this->preview($hr, $attachment);

        $payload = ['assembled' => true, 'grade_verified' => true];

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                ...$payload, 'documents' => [$attachment->id => 'not_approved'],
            ])
            ->assertOk()
            ->assertJsonPath('data.control_gates.employment_file.refusal', DocumentReview::NOT_APPROVED.'بيان الخدمة.pdf');

        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", ['action' => 'register'])
            ->assertStatus(422);

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/employment-file", [
                ...$payload, 'documents' => [$attachment->id => 'approved'],
            ])
            ->assertOk()
            ->assertJsonPath('data.control_gates.employment_file.refusal', null);

        $this->actingAs($hr, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", ['action' => 'register'])
            ->assertOk();
    }

    // --- fixtures ----------------------------------------------------------

    private function recordIntake(User $reviewer, Request $requestRecord, array $documents)
    {
        return $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/intake-gate", [
                'documents' => $documents,
                'facts_verified' => true,
            ])
            ->assertOk();
    }

    private function preview(User $reviewer, Attachment $attachment): void
    {
        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/requests/{$attachment->request_id}/attachments/{$attachment->id}/previewed")
            ->assertNoContent();
    }

    private function requestAt(string $stage, string $status): Request
    {
        return Request::create([
            'intake_receipt_number' => 'PM-RCV/2026/'.str_pad((string) (Request::count() + 1), 6, '0', STR_PAD_LEFT),
            'title' => 'طلب قيد التحقق من المستندات',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $status)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stage)->value('id'),
            'created_by_user_id' => $this->userWithRole('R01')->id,
            'submitted_at' => now(),
            'decision_grade' => 10,
            'entitled_grade' => 11,
            'grade_date' => '2024-03-01',
            'jurisdiction_test' => [
                'has_legal_basis' => true,
                'employee_covered' => true,
                'within_municipal_jurisdiction' => true,
                'committee_decides' => true,
                'final_approval_authority' => 'عميد البلدية',
                'requires_central_approval' => false,
            ],
        ]);
    }

    private function attach(Request $requestRecord, string $name, ?string $key = null): Attachment
    {
        return Attachment::create([
            'request_id' => $requestRecord->id,
            'disk' => 'local',
            'path' => "attachments/{$requestRecord->id}/".uniqid().'.pdf',
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'required_document_key' => $key,
            'file_section' => Attachment::DEFAULT_SUBMITTER_SECTION,
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
