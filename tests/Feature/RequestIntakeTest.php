<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RequestIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_user_can_load_intake_options_before_the_request_wildcard_route(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/requests/intake-options')
            ->assertOk()
            ->assertJsonPath('data.departments.0.code', 'ADM')
            ->assertJsonFragment(['code' => 'PROM']);
    }

    /**
     * Stage 72 rewrote this assertion from Stage 53's flat {ar, en} checklist
     * to [D] Appendix 57's grouped matrix — a legitimate update to a test whose
     * expectations this stage deliberately changes, not a regression fix.
     */
    public function test_intake_options_expose_appendix_57s_grouped_document_matrix_per_type(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/requests/intake-options')
            ->assertOk();

        $types = collect($response->json('data.types'));
        // Stage 53 — the 5 new types plus the 7 pre-existing ones, all active.
        $this->assertSame(12, $types->count());
        $this->assertEqualsCanonicalizing(
            ['PROM', 'LEAV', 'ALLW', 'SECD', 'GRIV', 'TRNS', 'EOSV', 'CONF', 'APPT', 'CTRC', 'SETL', 'PEVG'],
            $types->pluck('code')->all(),
        );

        // Appendix 57's shared basics table is merged into every type, so no
        // type is ever left with an empty checklist.
        foreach ($types as $type) {
            $groups = collect($type['required_documents'])->pluck('group');
            $this->assertGreaterThan(0, $groups->filter(fn ($group) => $group === 'basic')->count(), $type['code']);
        }

        $promotion = collect($types->firstWhere('code', 'PROM')['required_documents']);
        $this->assertSame(
            ['ar', 'en', 'group', 'condition'],
            array_keys($promotion->first()),
        );

        // Appendix 57's own الحالة column travels with the row it qualifies —
        // that is what makes an entry the appendix's third group (المشروطة).
        $appointmentDecision = $promotion->firstWhere('ar', 'قرار التعيين');
        $this->assertSame('basic', $appointmentDecision['group']);
        $this->assertSame('بحسب الموضوع', $appointmentDecision['condition']['ar']);

        $employmentData = $promotion->firstWhere('ar', 'البيانات الوظيفية');
        $this->assertNull($employmentData['condition']);

        // أولًا — ملف الترقية, the appendix's own per-type list.
        $this->assertNotNull($promotion->firstWhere('ar', 'كشف الأقدمية'));
        $this->assertSame('specific', $promotion->firstWhere('ar', 'كشف الأقدمية')['group']);
    }

    /**
     * Stage 72 — the "client-submittable only" filter, asserted rather than
     * merely documented: the committee's own internal opinions never appear on
     * a checklist shown to whoever files the request.
     */
    public function test_the_document_matrix_excludes_the_committees_own_internal_opinions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $excluded = ['الرأي القانوني', 'مذكرة إدارة الموارد البشرية', 'الرأي الإداري', 'السند القانوني', 'إحالة الرئيس المباشر'];

        foreach (RequestType::all() as $type) {
            foreach ($type->required_documents as $document) {
                $this->assertNotContains($document['ar'], $excluded, $type->code);
            }
        }
    }

    /**
     * Stage 72 — [D] names no per-type document file for ALLW/EOSV/APPT/CTRC,
     * so those four carry the shared basics and nothing else. That is an
     * honest gap, and this test is what stops a future session from quietly
     * refilling them with invented items.
     */
    public function test_types_d_does_not_cover_carry_the_shared_basics_only(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['ALLW', 'EOSV', 'APPT', 'CTRC'] as $code) {
            $groups = collect(RequestType::where('code', $code)->firstOrFail()->required_documents)
                ->pluck('group')
                ->unique()
                ->all();

            $this->assertSame(['basic'], $groups, $code);
        }

        // ...while a type the appendix does cover carries both groups.
        foreach (['PROM', 'LEAV', 'SECD', 'TRNS', 'CONF', 'SETL', 'GRIV', 'PEVG'] as $code) {
            $groups = collect(RequestType::where('code', $code)->firstOrFail()->required_documents)
                ->pluck('group')
                ->unique()
                ->all();

            $this->assertEqualsCanonicalizing(['basic', 'specific'], $groups, $code);
        }
    }

    public function test_an_authorized_user_can_intake_a_request_with_attachments(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();
        $type = RequestType::where('code', 'PROM')->firstOrFail();

        $response = $this->actingAs($admin, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب ترقية جديد',
                'description' => 'وصف طلب الترقية.',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
                'attachments' => [[
                    'file' => UploadedFile::fake()->create('promotion.pdf', 120, 'application/pdf'),
                    'label' => 'قرار الترقية',
                    // [D] Appendix 14 is now required per attachment at intake,
                    // not only on the later AttachmentController path.
                    'file_section' => 'supporting_documents',
                ]],
            ], ['Accept' => 'application/json']);

        // Diagram-alignment redesign (see AGENT_NOTES.md): intake now performs
        // one additional system hop into direct_manager_review in the same
        // request, so the request created here is never actually left
        // sitting at receive_from_municipality.
        // Intake mints no reference number: [D] Art. 15 says handing the
        // request over "لا يعد ... قيدًا". The employee gets a receipt
        // instead; the reference appears when the receiving body registers
        // the file (proved end to end in UnifiedNumberingTest), and the
        // submitter is told when it does.
        $response->assertCreated()
            ->assertJsonPath('data.reference_number', null)
            ->assertJsonPath('data.intake_receipt_number', 'PM-RCV/'.now()->format('Y').'/000001')
            ->assertJsonPath('data.status.code', 'in_review')
            ->assertJsonPath('data.current_stage.code', 'direct_manager_review');

        $requestRecord = Request::firstOrFail();
        $this->assertSame($admin->id, $requestRecord->created_by_user_id);
        $this->assertSame(11, $requestRecord->decision_grade);
        $this->assertNotNull($requestRecord->submitted_at);
        $this->assertSame(
            $requestRecord->submitted_at->copy()->startOfDay()->addDays($type->default_sla_days)->toDateString(),
            $requestRecord->due_date?->toDateString(),
        );
        // The intake stage log at receive_from_municipality is still written
        // first — this proves the truthful origin point is preserved even
        // though the request has already moved on by the time this
        // response is read.
        $this->assertDatabaseHas('request_stage_logs', [
            'request_id' => $requestRecord->id,
            'to_stage_id' => WorkflowStage::where('code', 'receive_from_municipality')->value('id'),
            'action' => 'intake',
        ]);
        $this->assertDatabaseHas('request_stage_logs', [
            'request_id' => $requestRecord->id,
            'from_stage_id' => WorkflowStage::where('code', 'receive_from_municipality')->value('id'),
            'to_stage_id' => WorkflowStage::where('code', 'direct_manager_review')->value('id'),
            'action' => 'submit',
            'acted_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $requestRecord->id,
            'to_status_id' => RequestStatus::where('code', 'new')->value('id'),
        ]);
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $requestRecord->id,
            'to_status_id' => RequestStatus::where('code', 'in_review')->value('id'),
        ]);

        $attachment = Attachment::firstOrFail();
        $this->assertSame('قرار الترقية', $attachment->label);
        $this->assertSame('supporting_documents', $attachment->file_section);
        Storage::disk('local')->assertExists($attachment->path);
    }

    /**
     * [D] Appendix 14: "ويمنع حفظ الملفات بصورة عشوائية دون تصنيف". Intake was
     * the one write path that still produced an unclassified attachment, so
     * an unclassified file now refuses the whole submission rather than being
     * stored as غير مصنف.
     */
    public function test_intake_refuses_an_attachment_with_no_file_section(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();
        $type = RequestType::where('code', 'PROM')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب بمرفق غير مصنف',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
                'attachments' => [[
                    'file' => UploadedFile::fake()->create('unclassified.pdf', 60, 'application/pdf'),
                ]],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments.0.file_section');

        // The refusal is the whole submission, not just the document: a
        // request saved without its file would be worse than neither.
        $this->assertSame(0, Request::count());
        $this->assertSame(0, Attachment::count());
    }

    /**
     * The submitter is offered only the folders their own file can be in.
     * Appendix 14's later-cycle folders (مذكرة العرض، المحضر والقرار،
     * الاعتماد …) are artifacts the committee produces, so naming one here is
     * refused even though AttachmentController still accepts it from the
     * roles that genuinely upload those documents.
     */
    public function test_intake_refuses_a_committee_cycle_file_section(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();
        $type = RequestType::where('code', 'PROM')->firstOrFail();

        // Valid on the AttachmentController path, refused on this one.
        $this->assertArrayHasKey('minutes_decision', Attachment::FILE_SECTIONS);
        $this->assertNotContains('minutes_decision', Attachment::SUBMITTER_FILE_SECTIONS);

        $this->actingAs($admin, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب بتصنيف لا يخص مقدم الطلب',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
                'attachments' => [[
                    'file' => UploadedFile::fake()->create('decision.pdf', 60, 'application/pdf'),
                    'file_section' => 'minutes_decision',
                ]],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments.0.file_section');

        $this->assertSame(0, Attachment::count());
    }

    /**
     * Stage 70 replaced this test's original subject. It used to assert the
     * per-department, per-year `YYYY-DEPT-NNNNNN` reference the old
     * RequestReferenceGenerator minted at intake; [D] Appendix 15's scheme has
     * no department segment and Art. 20 grants nothing at intake at all, so
     * what increments here now is the intake receipt. The reference series'
     * own increment is covered in UnifiedNumberingTest, at the قيد.
     */
    public function test_intake_receipts_increment_within_the_year(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();
        // Stage 83 — two different types, deliberately: [D] Appendix 16 now
        // refuses a second request while an open file on the *same* subject
        // exists ("لا تنشأ معاملة جديدة"), and this test is about the receipt
        // series, which is global per year rather than per type.
        $types = RequestType::whereIn('code', ['PROM', 'LEAV'])->get()->keyBy('code');

        foreach ([1 => 'PROM', 2 => 'LEAV'] as $sequence => $code) {
            $this->actingAs($admin, 'sanctum')
                ->postJson('/api/requests', [
                    'title' => "طلب {$sequence}",
                    'department_id' => $department->id,
                    'request_type_id' => $types[$code]->id,
                    'decision_grade' => 9,
                ])
                ->assertCreated()
                ->assertJsonPath('data.reference_number', null)
                ->assertJsonPath(
                    'data.intake_receipt_number',
                    'PM-RCV/'.now()->format('Y').'/'.sprintf('%06d', $sequence),
                );
        }
    }

    public function test_decision_grade_is_required_when_the_type_has_a_ministry_threshold(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/requests', [
                'title' => 'طلب بلا درجة',
                'department_id' => Department::where('code', 'ADM')->value('id'),
                'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision_grade');

        $this->assertDatabaseCount('requests', 0);
    }
}
