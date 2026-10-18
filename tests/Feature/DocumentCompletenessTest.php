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
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\PassesControlGates;
use Tests\TestCase;

/**
 * Stage 85 — [D] Appendix 57's matrix stops advising and starts binding, at
 * both ends of the intake half.
 *
 * Three rules, one predicate: a submission must cover every row the appendix
 * states without a qualifier ([G]'s «الرجاء إرفاق المستندات المطلوبة»), the
 * officer's gate reads the submitter's own files instead of asking about them
 * again, and a file that does not cover them cannot reach a committee agenda
 * ([F] footer 2, "لا يُعرض أي طلب على اللجنة قبل استكمال المستندات المطلوبة").
 */
class DocumentCompletenessTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    // --- what "mandatory" means ----------------------------------------

    /**
     * The rule reads Stage 72's own stored data, not a new list.
     *
     * A row Appendix 57 qualifies inline ("بحسب الموضوع") is conditional and
     * therefore optional; an unqualified one is not. This is the same
     * distinction Stage 78's officer gate has read since it was built, which
     * is why this stage moves a bar rather than raising one.
     */
    public function test_mandatory_is_exactly_the_rows_appendix_57_states_without_a_qualifier(): void
    {
        $type = RequestType::where('code', 'PROM')->firstOrFail();
        $mandatory = app(DocumentCompletenessService::class)->mandatoryDocuments($type);

        $this->assertContains('بيان الدرجة الحالية', array_column($mandatory, 'ar'));
        // كشف الخدمة carries Appendix 57's own "بحسب الموضوع".
        $this->assertNotContains('كشف الخدمة', array_column($mandatory, 'ar'));

        foreach ($type->documentOptions() as $key => $document) {
            $this->assertSame(
                $document['condition'] === null,
                array_key_exists($key, $mandatory),
                $document['ar'],
            );
        }
    }

    /**
     * The «مطلوب» box outranks the condition in both directions: an admin can
     * make an unqualified row optional and a qualified one required.
     */
    public function test_an_explicit_required_flag_outranks_the_condition(): void
    {
        $type = RequestType::where('code', 'PROM')->firstOrFail();
        $documents = $type->required_documents;

        $unqualified = array_search('بيان الدرجة الحالية', array_column($documents, 'ar'), true);
        $qualified = array_search('كشف الخدمة', array_column($documents, 'ar'), true);
        $documents[$unqualified]['required'] = false;
        $documents[$qualified]['required'] = true;
        $type->update(['required_documents' => $documents]);

        $mandatory = array_column(app(DocumentCompletenessService::class)->mandatoryDocuments($type->fresh()), 'ar');

        $this->assertNotContains('بيان الدرجة الحالية', $mandatory);
        $this->assertContains('كشف الخدمة', $mandatory);
    }

    // --- the submission rule --------------------------------------------

    public function test_an_intake_missing_a_mandatory_document_is_refused_with_that_document_named(): void
    {
        $type = RequestType::where('code', 'PROM')->firstOrFail();
        $attachments = $this->mandatoryAttachments($type);
        $dropped = array_pop($attachments);

        $missingLabel = app(DocumentCompletenessService::class)
            ->mandatoryDocuments($type)[$dropped['required_document_key']]['ar'];

        $response = $this->actingAs($this->employee(), 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب ترقية ناقص',
                'department_id' => Department::where('code', 'ADM')->value('id'),
                'request_type_id' => $type->id,
                'decision_grade' => 9,
                'entitled_grade' => 9,
                'grade_date' => '2024-03-01',
                'attachments' => $attachments,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments');

        // [G]'s own wording, and the document named rather than a bare count.
        $this->assertStringContainsString('الرجاء إرفاق المستندات المطلوبة', $response->json('errors.attachments.0'));
        $this->assertStringContainsString($missingLabel, $response->json('errors.attachments.0'));

        // The refusal is the whole submission, not just the document.
        $this->assertSame(0, Request::count());
        $this->assertSame(0, Attachment::count());
    }

    public function test_an_intake_with_no_attachments_at_all_is_refused(): void
    {
        // The case that matters most, and the one a rule attached to a
        // `nullable` field would never have run for.
        $this->actingAs($this->employee(), 'sanctum')
            ->postJson('/api/requests', [
                'title' => 'طلب بلا مرفقات',
                'department_id' => Department::where('code', 'ADM')->value('id'),
                'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
                'decision_grade' => 9,
                'entitled_grade' => 9,
                'grade_date' => '2024-03-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments');

        $this->assertSame(0, Request::count());
    }

    public function test_covering_every_mandatory_row_is_enough_and_conditional_rows_are_never_required(): void
    {
        $type = RequestType::where('code', 'PROM')->firstOrFail();
        $conditionalKeys = array_diff(
            array_keys($type->documentOptions()),
            array_keys(app(DocumentCompletenessService::class)->mandatoryDocuments($type)),
        );

        $this->assertNotEmpty($conditionalKeys, 'PROM should have conditional rows for this test to mean anything.');

        $this->actingAs($this->employee(), 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب ترقية مكتمل',
                'department_id' => Department::where('code', 'ADM')->value('id'),
                'request_type_id' => $type->id,
                'decision_grade' => 9,
                'entitled_grade' => 9,
                'grade_date' => '2024-03-01',
                'attachments' => $this->mandatoryAttachments($type),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        // Nothing was attached for any conditional row.
        $this->assertSame(
            [],
            Attachment::whereIn('required_document_key', $conditionalKeys)->pluck('id')->all(),
        );
    }

    /**
     * The four types [D] covers with the shared basics only state exactly one
     * row unconditionally, so the rule is cheap where the appendix is quiet —
     * it is not a blanket "attach everything".
     */
    public function test_a_type_appendix_57_barely_covers_needs_one_document(): void
    {
        $type = RequestType::where('code', 'ALLW')->firstOrFail();
        $attachments = $this->mandatoryAttachments($type);

        $this->assertCount(1, $attachments);

        $this->actingAs($this->employee(), 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب علاوة',
                'department_id' => Department::where('code', 'ADM')->value('id'),
                'request_type_id' => $type->id,
                'decision_grade' => 9,
                'entitled_grade' => 9,
                'grade_date' => '2024-03-01',
                'attachments' => $attachments,
            ], ['Accept' => 'application/json'])
            ->assertCreated();
    }

    /**
     * The per-request attachment cap has to clear the largest matrix, or the
     * submission rule contradicts it: TRNS states THIRTEEN documents
     * unconditionally, and a cap of ten made that type unfilable — refused for
     * documents it was simultaneously refused permission to attach.
     */
    public function test_the_type_with_the_most_mandatory_documents_can_still_be_filed(): void
    {
        $type = RequestType::where('code', 'TRNS')->firstOrFail();
        $attachments = $this->mandatoryAttachments($type);

        $this->assertGreaterThan(10, count($attachments));

        $this->actingAs($this->employee(), 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب نقل',
                'department_id' => Department::where('code', 'ADM')->value('id'),
                'request_type_id' => $type->id,
                'decision_grade' => 9,
                'entitled_grade' => 9,
                'grade_date' => '2024-03-01',
                'attachments' => $attachments,
            ], ['Accept' => 'application/json'])
            ->assertCreated();
    }

    // The officer's gate no longer reads Appendix 57's rows: since
    // 2026-10-09 it judges each attached file (DocumentReviewTest), and this
    // matrix is enforced here, at intake and before the agenda.

    // --- [F] footer 2 — the agenda gate -----------------------------------

    public function test_an_incomplete_file_cannot_reach_an_agenda_and_can_once_it_is_completed(): void
    {
        [$head, $meeting] = $this->committeeMeeting();
        $requestRecord = $this->presentableRequest();

        $response = $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestRecord->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('request_id');

        $this->assertStringContainsString(
            'لا يُعرض أي طلب على اللجنة قبل استكمال المستندات المطلوبة',
            $response->json('errors.request_id.0'),
        );

        $this->supplyRequiredDocuments($requestRecord);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestRecord->id])
            ->assertCreated();
    }

    /** An administrative item has no file to be incomplete. */
    /**
     * Stage 102 — this used to prove an administrative item skipped the
     * completeness gate. There are no such items any more: the agenda holds
     * pending-list requests and appeals only, so the gate has no bypass.
     */
    public function test_there_is_no_administrative_item_to_bypass_the_gate(): void
    {
        [$head, $meeting] = $this->committeeMeeting();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", [
                'item_type' => 'administrative',
                'subject' => 'بند إداري',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_type');
    }

    // --- fixtures --------------------------------------------------------

    /** @return array{0: User, 1: Meeting} */
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

    /** A file that clears every agenda gate upstream of this stage's own. */
    private function presentableRequest(): Request
    {
        $requestRecord = Request::create([
            'reference_number' => 'PM-COM/2026/'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب جاهز للعرض',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'ready')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $this->employee()->id,
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
            'intake_receipt_number' => 'PM-RCV/2026/'.str_pad((string) (Request::count() + 1), 6, '0', STR_PAD_LEFT),
            'title' => 'طلب عند فحص الاكتمال',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_review')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'requirements_check')->value('id'),
            'created_by_user_id' => $this->employee()->id,
            'submitted_at' => now(),
            'decision_grade' => 10,
            'entitled_grade' => 10,
            'grade_date' => '2024-03-01',
        ]);
    }

    private function employee(): User
    {
        return $this->userWithRole('R01');
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
