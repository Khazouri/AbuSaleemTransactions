<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\Request;
use App\Models\RequestDocumentConflict;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\Lifecycle\DocumentValidityRules;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PassesControlGates;
use Tests\TestCase;

/**
 * Stage 83 — [D] Appendix 30 (تعارض المستندات) and Appendix 31 (التحقق من صحة
 * المستندات).
 */
class DocumentIntegrityTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;

    public function test_an_open_conflict_refuses_agenda_insertion_and_resolving_it_lifts_the_refusal(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$rapporteur, $head, $meeting] = $this->committeeAndMeeting();
        $requestRecord = $this->presentableRequest();

        $conflictId = $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/document-conflicts", [
                'conflict_kind' => 'appointment_date',
                'detail' => 'قرار التعيين يذكر 2019 بينما كشف الخدمة يذكر 2020.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestRecord->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('request_id');

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/document-conflicts/{$conflictId}/resolve", [
                'authority_consulted' => 'إدارة الموارد البشرية بالبلدية',
                'authoritative_document' => 'قرار التعيين الصادر بتاريخ 2019/03/01.',
                'correction_note' => 'صُحح تاريخ التعيين في ملف الموظف.',
            ])
            ->assertOk();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestRecord->id])
            ->assertCreated();
    }

    /**
     * "ولا يجوز للجنة اختيار أحد المستندين بناءً على تقدير شخصي" — a resolution
     * that names no external determination is simply not recordable.
     */
    public function test_a_resolution_must_name_the_authority_the_document_and_the_correction(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$rapporteur] = $this->committeeAndMeeting();
        $requestRecord = $this->presentableRequest();

        $conflictId = $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/document-conflicts", [
                'conflict_kind' => 'grade',
                'detail' => 'اختلاف الدرجة بين مستندين رسميين.',
            ])->json('data.id');

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/document-conflicts/{$conflictId}/resolve", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['authority_consulted', 'authoritative_document', 'correction_note']);

        $this->assertNull(RequestDocumentConflict::find($conflictId)->resolved_at);
    }

    public function test_a_conflict_cannot_name_another_files_document_and_cannot_be_resolved_twice(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$rapporteur] = $this->committeeAndMeeting();
        $requestRecord = $this->presentableRequest();
        $stranger = $this->presentableRequest();

        $foreign = Attachment::create([
            'request_id' => $stranger->id,
            'disk' => 'local',
            'path' => 'attachments/x/foreign.pdf',
            'original_name' => 'foreign.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'file_section' => 'supporting_documents',
        ]);

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/document-conflicts", [
                'conflict_kind' => 'name',
                'detail' => 'اختلاف الاسم.',
                'attachment_ids' => [$foreign->id],
            ])
            ->assertStatus(422);

        $conflictId = $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/document-conflicts", [
                'conflict_kind' => 'name',
                'detail' => 'اختلاف الاسم.',
            ])->json('data.id');

        $payload = [
            'authority_consulted' => 'السجل المدني',
            'authoritative_document' => 'شهادة الميلاد.',
            'correction_note' => 'صُحح الاسم.',
        ];

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/document-conflicts/{$conflictId}/resolve", $payload)
            ->assertOk();

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/document-conflicts/{$conflictId}/resolve", $payload)
            ->assertStatus(422);
    }

    /**
     * A conflict can also surface *after* an item is already on the agenda,
     * which the insertion gate by definition cannot catch — so readiness asks
     * the same question at the sitting.
     */
    public function test_a_conflict_raised_after_insertion_becomes_a_readiness_exception(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$rapporteur, $head, $meeting] = $this->committeeAndMeeting();
        $requestRecord = $this->presentableRequest();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestRecord->id])
            ->assertCreated();

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/document-conflicts", [
                'conflict_kind' => 'service_period',
                'detail' => 'اختلاف مدة الخدمة بين كشف الخدمة وإفادة الجهة السابقة.',
            ])->assertCreated();

        $codes = collect(
            $this->actingAs($head, 'sanctum')
                ->getJson("/api/meetings/{$meeting->id}/readiness")
                ->assertOk()
                ->json('data.exceptions'),
        )->pluck('code');

        $this->assertContains('unresolved_document_conflict', $codes->all());
    }

    public function test_appendix_31s_nine_checks_record_with_the_two_conditional_ones_optional(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$rapporteur] = $this->committeeAndMeeting();
        $requestRecord = $this->presentableRequest();
        $attachment = $this->attachment($requestRecord);

        // The seven unconditional checks may not be waived.
        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/attachments/{$attachment->id}/validity", [
                'checks' => [...$this->soundChecks(), 'issuing_body' => 'not_applicable'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('checks');

        // An unanswered check is refused too — the record is the nine, or none.
        $partial = $this->soundChecks();
        unset($partial['signature']);
        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/attachments/{$attachment->id}/validity", ['checks' => $partial])
            ->assertStatus(422);

        // The appendix's own two qualifiers — الختم عند الحاجة and مطابقة
        // الصورة للأصل عند اشتراطها — may honestly be غير منطبق.
        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/attachments/{$attachment->id}/validity", [
                'checks' => [
                    ...$this->soundChecks(),
                    'stamp' => 'not_applicable',
                    'copy_matches_original' => 'not_applicable',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.verdict', 'sound');
    }

    /**
     * A `no` records a finding and refuses nothing: the appendix's own verb is
     * "**يجوز** تعليق", and the hold it describes is Art. 105's suspension.
     */
    public function test_a_doubtful_document_is_recorded_and_blocks_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$rapporteur, $head, $meeting] = $this->committeeAndMeeting();
        $requestRecord = $this->presentableRequest();
        $attachment = $this->attachment($requestRecord);

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/attachments/{$attachment->id}/validity", [
                'checks' => [...$this->soundChecks(), 'no_unapproved_alteration' => 'no'],
            ])
            ->assertOk()
            ->assertJsonPath('data.verdict', 'doubtful');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestRecord->id])
            ->assertCreated();

        $card = collect(
            $this->actingAs($rapporteur, 'sanctum')
                ->getJson("/api/requests/{$requestRecord->id}/lifecycle")
                ->assertOk()
                ->json('data.document_validity'),
        )->firstWhere('attachment_id', $attachment->id);

        $this->assertSame('doubtful', $card['card']['verdict']);
        $this->assertCount(9, $card['card']['checks']);
    }

    public function test_a_role_without_the_grant_cannot_record_a_conflict(): void
    {
        $this->seed(DatabaseSeeder::class);
        $requestRecord = $this->presentableRequest();
        $member = $this->userWithRole('R04');

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/document-conflicts", [
                'conflict_kind' => 'name',
                'detail' => 'اختلاف الاسم.',
            ])
            ->assertForbidden();
    }

    // --- fixtures ----------------------------------------------------------

    /** @return array<string, string> */
    private function soundChecks(): array
    {
        return collect(DocumentValidityRules::CHECKS)->map(fn () => 'yes')->all();
    }

    /** @return array{0: User, 1: User, 2: Meeting} */
    private function committeeAndMeeting(): array
    {
        $rapporteur = $this->userWithRole('R02');
        $head = $this->userWithRole('R03');

        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع اختبار سلامة المستندات',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);

        return [$rapporteur, $head, $meeting];
    }

    private function presentableRequest(): Request
    {
        $employee = $this->userWithRole('R01');

        $requestRecord = Request::create([
            'reference_number' => 'PM-COM/2026/'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب اختبار سلامة المستندات',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'ready')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $employee->id,
            'submitted_at' => now(),
        ]);

        // Stage 68's agenda gate is upstream of this stage's, so a fixture
        // meant to test the conflict gate has to clear the legal one first.
        $requestRecord->legalReviews()->create([
            'verdict' => 'sound_ready',
            'committee_mandate' => 'decision',
            'reviewed_at' => now(),
        ]);

        // Stage 85 — [F] footer 2 now refuses an agenda insertion for a file
        // that does not cover its type's mandatory [D] Appendix 57 rows.
        // Supplied here so these tests stay about what they were written for;
        // the rule itself has its own coverage in DocumentCompletenessTest.
        $this->supplyRequiredDocuments($requestRecord);

        return $requestRecord->refresh();
    }

    private function attachment(Request $requestRecord): Attachment
    {
        return Attachment::create([
            'request_id' => $requestRecord->id,
            'disk' => 'local',
            'path' => "attachments/{$requestRecord->id}/decision.pdf",
            'original_name' => 'decision.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'file_section' => 'supporting_documents',
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
