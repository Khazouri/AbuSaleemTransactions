<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\IntakeGateService;
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
        // `section` joined the entry when the submitter started naming which
        // recommended document a file provides: the [D] Appendix 14 folder is
        // derived from that answer, and the seeder declares it per row rather
        // than it being guessed from the label afterwards.
        $this->assertSame(
            ['ar', 'en', 'group', 'condition', 'section'],
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
                    // The submitter names which of the type's [D] Appendix 57
                    // recommended documents this file is; the Appendix 14
                    // folder is derived from that answer.
                    'required_document_key' => $this->documentKeyFor($type, 'كشف الخدمة'),
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
        $this->assertSame($this->documentKeyFor($type, 'كشف الخدمة'), $attachment->required_document_key);
        // كشف الخدمة is a service-record extract, so the folder derived from it
        // is الملف الوظيفي — not the المستندات المؤيدة default.
        $this->assertSame('service_file', $attachment->file_section);
        Storage::disk('local')->assertExists($attachment->path);
    }

    /**
     * A file with no document named refuses the whole submission.
     *
     * Required rather than defaulted, for the reason Appendix 14 already gives
     * about classification generally: a default would be an answer the
     * submitter never gave. And the refusal is the whole request, not just the
     * document — a request saved without the file the employee meant to attach
     * is worse than neither.
     */
    public function test_intake_refuses_an_attachment_with_no_document_named(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();
        $type = RequestType::where('code', 'PROM')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب بمرفق بلا نوع',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
                'attachments' => [[
                    'file' => UploadedFile::fake()->create('unnamed.pdf', 60, 'application/pdf'),
                ]],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments.0.required_document_key');

        $this->assertSame(0, Request::count());
        $this->assertSame(0, Attachment::count());
    }

    /**
     * A key is only meaningful against the type whose matrix produced it, so a
     * key belonging to another type is refused rather than stored as an answer
     * to a question this request was never asked. The client cannot produce
     * one; an API caller can.
     */
    public function test_intake_refuses_a_document_key_from_another_request_type(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();
        $type = RequestType::where('code', 'PROM')->firstOrFail();
        $otherType = RequestType::where('code', 'TRNS')->firstOrFail();

        // A genuine key, just not one of PROM's.
        $foreignKey = $this->documentKeyFor($otherType, 'رأي أو موافقة الجهة المنقول إليها');
        $this->assertArrayNotHasKey($foreignKey, $type->documentOptions());

        $this->actingAs($admin, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب بمستند من نوع آخر',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
                'attachments' => [[
                    'file' => UploadedFile::fake()->create('foreign.pdf', 60, 'application/pdf'),
                    'required_document_key' => $foreignKey,
                ]],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments.0.required_document_key');

        $this->assertSame(0, Attachment::count());
    }

    /**
     * The Appendix 14 folder is derived from the document named, and the
     * matrix's own escape hatch still lands somewhere honest.
     *
     * `other` is what a submitter picks for material no Appendix 57 row names —
     * the appendix covers only eight of the twelve types with a specific list —
     * and it files as المستندات المؤيدة, which is what such a document is.
     */
    public function test_a_type_specific_document_and_other_both_file_as_supporting_documents(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();
        $type = RequestType::where('code', 'PROM')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب ترقية بمرفقين',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
                'attachments' => [
                    [
                        'file' => UploadedFile::fake()->create('grade.pdf', 60, 'application/pdf'),
                        'required_document_key' => $this->documentKeyFor($type, 'بيان الدرجة الحالية'),
                    ],
                    [
                        'file' => UploadedFile::fake()->create('extra.pdf', 60, 'application/pdf'),
                        'required_document_key' => 'other',
                    ],
                ],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $attachments = Attachment::orderBy('id')->get();
        $this->assertSame(['supporting_documents', 'supporting_documents'], $attachments->pluck('file_section')->all());
        $this->assertSame('other', $attachments->last()->required_document_key);
    }

    /**
     * The submitter's stored key is the one Stage 78's intake gate answers under.
     *
     * This is the assertion that keeps the two features on one vocabulary: if a
     * later change gives either side its own identifier, the officer's
     * completeness check and the employee's uploads would silently stop
     * describing the same rows.
     */
    public function test_the_stored_key_is_the_intake_gates_own_document_key(): void
    {
        $this->seed(DatabaseSeeder::class);
        $type = RequestType::where('code', 'PROM')->firstOrFail();

        $expected = [];
        foreach (array_values($type->required_documents) as $index => $document) {
            $expected[] = IntakeGateService::documentKey($index, $document['ar']);
        }

        $this->assertNotEmpty($expected);
        $this->assertSame($expected, array_keys($type->documentOptions()));
    }

    /** The slug a given Appendix 57 row is offered and stored under. */
    private function documentKeyFor(RequestType $type, string $labelAr): string
    {
        foreach ($type->documentOptions() as $key => $document) {
            if ($document['ar'] === $labelAr) {
                return $key;
            }
        }

        $this->fail("No seeded document labelled {$labelAr} on type {$type->code}.");
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
