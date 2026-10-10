<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Attachment;
use App\Models\AttachmentPreview;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestStepReview;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use App\Models\WorkflowVersion;
use App\Services\DocumentCompletenessService;
use App\Services\DocumentReview;
use App\Services\StepChecklistService;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\PassesControlGates;
use Tests\TestCase;

/**
 * Workflow designer — slice 4, spec §1's success case completed: «تدقيق مالي»
 * (R05) now has a checklist, and its «اعتماد التدقيق» waits on it. Its
 * «تعليق» stays on the step and «إعادة للمدير» sends the file back; neither
 * waits on the checklist.
 */
class StepChecklistGateTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;

    private User $manager;

    private User $auditor;

    private string $documentKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $this->manager = $this->userWithRole('R01');
        $this->auditor = $this->userWithRole('R05');
    }

    public function test_the_checked_outcome_waits_on_the_card_and_preview_matches_the_endpoint(): void
    {
        $atAudit = $this->fileAtAudit();

        $blocked = collect($this->actingAs($this->auditor, 'sanctum')->getJson("/api/requests/{$atAudit->id}")
            ->assertOk()->json('data.blocked_transitions'))->firstWhere('action', 'custom_audit_done');
        $this->assertSame(StepChecklistService::NOT_RECORDED, $blocked['reason']);
        $this->assertTrue($blocked['requires_checks']);

        $this->actingAs($this->auditor, 'sanctum')
            ->postJson("/api/requests/{$atAudit->id}/transition", ['action' => 'custom_audit_done'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.action.0', StepChecklistService::NOT_RECORDED);

        // Outcomes that do not wait on the checklist are not held by it.
        $available = collect($this->actingAs($this->auditor, 'sanctum')->getJson("/api/requests/{$atAudit->id}")->json('data.available_transitions'))->pluck('action');
        $this->assertContains('custom_audit_hold', $available->all());

        $this->answer($atAudit, $this->cleanAnswers($atAudit));

        $this->actingAs($this->auditor, 'sanctum')
            ->postJson("/api/requests/{$atAudit->id}/transition", ['action' => 'custom_audit_done'])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'receive_and_register');
    }

    public function test_the_blocking_answer_holds_the_file(): void
    {
        $atAudit = $this->fileAtAudit();
        $this->answer($atAudit, ['budget' => 'no'] + $this->cleanAnswers($atAudit));

        $this->assertSame(
            sprintf(StepChecklistService::BLOCKING, 'هل يوجد اعتماد مالي؟'),
            app(StepChecklistService::class)->refusalReason($atAudit),
        );
    }

    public function test_every_item_type_must_be_answered(): void
    {
        $atAudit = $this->fileAtAudit();
        $clean = $this->cleanAnswers($atAudit);
        $service = app(StepChecklistService::class);

        foreach (['budget', 'remark', 'sworn', 'doc'] as $missing) {
            $answers = $clean;
            unset($answers[$missing]);
            $this->answer($atAudit, $answers);
            $this->assertNotNull($service->refusalReason($atAudit), "{$missing} left blank must refuse");
        }

        $this->answer($atAudit, ['remark' => '   '] + $clean);
        $this->assertSame(StepChecklistService::UNANSWERED.'ملاحظة المدقق', $service->refusalReason($atAudit));
    }

    public function test_an_attachment_item_with_no_file_under_its_key_refuses(): void
    {
        $atAudit = $this->fileAtAudit();
        $answers = $this->cleanAnswers($atAudit);
        $atAudit->attachments()->where('required_document_key', $this->documentKey)->delete();
        $this->answer($atAudit, $answers);

        $this->assertSame(StepChecklistService::NO_FILE.'كشف الراتب', app(StepChecklistService::class)->refusalReason($atAudit));
    }

    public function test_a_newer_upload_under_the_key_needs_a_fresh_verdict(): void
    {
        $atAudit = $this->fileAtAudit();
        $this->answer($atAudit, $this->cleanAnswers($atAudit));
        $this->assertNull(app(StepChecklistService::class)->refusalReason($atAudit));

        $this->attach($atAudit, $this->documentKey, 'كشف الراتب المحدث.pdf');

        $this->assertStringStartsWith(DocumentReview::NOT_REVIEWED, app(StepChecklistService::class)->refusalReason($atAudit));
    }

    public function test_a_self_loop_keeps_the_card_and_a_return_starts_a_new_one(): void
    {
        $atAudit = $this->fileAtAudit();
        $workflow = app(WorkflowService::class);
        $this->answer($atAudit, $this->cleanAnswers($atAudit));

        $held = $workflow->transition($atAudit, 'custom_audit_hold', $this->auditor);
        $this->assertNull(app(StepChecklistService::class)->refusalReason($held));

        $back = $workflow->transition($held, 'custom_audit_return', $this->auditor);
        $again = $workflow->transition($back, 'forward', $this->manager);
        $this->assertSame('تدقيق مالي', $again->currentStage->name_ar);
        $this->assertSame(StepChecklistService::NOT_RECORDED, app(StepChecklistService::class)->refusalReason($again));
        $this->assertSame(1, RequestStepReview::count());
    }

    public function test_the_filer_holding_the_role_can_neither_see_nor_take_the_checked_outcome(): void
    {
        $atAudit = $this->fileAtAudit();
        $filer = User::findOrFail($atAudit->created_by_user_id);
        $filer->roles()->attach(Role::where('code', 'R05')->value('id'));
        $workflow = app(WorkflowService::class);

        $this->assertNotContains('custom_audit_done', $workflow->availableActions($atAudit, $filer)->all());
        // The unchecked outcomes stay theirs: the refusal is tied to requires_checks (Deviation 7).
        $this->assertContains('custom_audit_hold', $workflow->availableActions($atAudit, $filer)->all());

        $this->answer($atAudit, $this->cleanAnswers($atAudit));
        $this->expectExceptionObject(WorkflowTransitionException::cannotCompleteOwnStep());
        $workflow->transition($atAudit, 'custom_audit_done', $filer);
    }

    /** A promotion filed under the finance workflow and forwarded by its manager to «تدقيق مالي». */
    private function fileAtAudit(): Request
    {
        $promotion = RequestType::where('code', 'PROM')->firstOrFail();
        $this->documentKey = (string) array_key_first(app(DocumentCompletenessService::class)->mandatoryDocuments($promotion));
        $version = $this->publishFinanceWorkflow();
        $promotion->update(['workflow_id' => $version->workflow_id]);

        return app(WorkflowService::class)->transition($this->file($promotion), 'forward', $this->manager);
    }

    /** Every item cleared: the file under the attachment key opened and approved by the auditor. */
    private function cleanAnswers(Request $requestRecord): array
    {
        $file = $requestRecord->attachments()->where('required_document_key', $this->documentKey)->latest('id')->firstOrFail();
        AttachmentPreview::firstOrCreate(['attachment_id' => $file->id, 'user_id' => $this->auditor->id]);

        return ['budget' => 'yes', 'remark' => 'لا ملاحظات', 'sworn' => true, 'doc' => [$file->id => 'approved']];
    }

    /** Stores a card for the current arrival directly; the endpoint is Task 4's. */
    private function answer(Request $requestRecord, array $answers): void
    {
        RequestStepReview::updateOrCreate(
            ['request_id' => $requestRecord->id, 'arrival_log_id' => app(StepChecklistService::class)->arrival($requestRecord)->id],
            ['workflow_stage_id' => $requestRecord->current_stage_id, 'answers' => $answers, 'reviewed_by_user_id' => $this->auditor->id, 'reviewed_at' => now()],
        );
    }

    private function attach(Request $requestRecord, string $key, string $name): Attachment
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

    /** «مسار مالي»: Standard plus «تدقيق مالي» (R05) with a four-item checklist, published. */
    private function publishFinanceWorkflow(): WorkflowVersion
    {
        $admin = $this->actingAs($this->admin(), 'sanctum');
        $id = $admin->postJson('/api/workflows', ['code' => 'finance', 'name_ar' => 'مسار مالي', 'from_version_id' => Workflow::standard()->publishedVersion->id])
            ->assertCreated()->json('data.id');
        $draft = WorkflowVersion::findOrFail($id);
        $r05 = Role::where('code', 'R05')->value('id');

        $audit = WorkflowStage::create([
            'workflow_version_id' => $draft->id, 'code' => 'custom_audit', 'order_no' => 99, 'name_ar' => 'تدقيق مالي',
            'checklist' => [
                ['key' => 'budget', 'type' => 'yes_no', 'label_ar' => 'هل يوجد اعتماد مالي؟', 'label_en' => null, 'blocking_answer' => 'no'],
                ['key' => 'doc', 'type' => 'attachment', 'label_ar' => 'كشف الراتب', 'label_en' => null, 'document_key' => $this->documentKey],
                ['key' => 'remark', 'type' => 'note', 'label_ar' => 'ملاحظة المدقق', 'label_en' => null],
                ['key' => 'sworn', 'type' => 'attestation', 'label_ar' => 'أقر بصحة ما سبق', 'label_en' => null],
            ],
        ]);
        WorkflowTransition::query()->where('from_stage_id', $draft->stage('direct_manager_review')->id)->where('action', 'forward')
            ->update(['to_stage_id' => $audit->id]);
        foreach ([
            ['custom_audit_done', 'receive_and_register', 'اعتماد التدقيق', true, null],
            ['custom_audit_hold', 'custom_audit', 'تعليق', false, null],
            ['custom_audit_return', 'direct_manager_review', 'إعادة للمدير', false, 'in_review'],
        ] as [$action, $to, $label, $checked, $status]) {
            WorkflowTransition::create([
                'from_stage_id' => $audit->id,
                'to_stage_id' => $to === 'custom_audit' ? $audit->id : $draft->stage($to)->id,
                'action' => $action,
                'label_ar' => $label,
                'required_role_id' => $r05,
                'requires_checks' => $checked,
                'set_status_id' => $status ? RequestStatus::where('code', $status)->value('id') : null,
            ]);
        }

        $admin->postJson("/api/workflow-versions/{$draft->id}/publish")->assertOk();

        return $draft->fresh();
    }

    /** A request filed through intake by an employee whose manager is $this->manager. */
    private function file(RequestType $type): Request
    {
        $filer = User::factory()->create([
            'is_active' => true,
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'manager_id' => $this->manager->id,
        ]);
        $filer->roles()->attach(Role::where('code', 'R01')->value('id'));

        $id = $this->actingAs($filer, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب '.$type->code,
                'request_type_id' => $type->id,
                'decision_grade' => 9,
                'entitled_grade' => 9,
                'grade_date' => '2024-03-01',
                'attachments' => $this->mandatoryAttachments($type),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        return Request::findOrFail($id);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@abusaleem.test')->firstOrFail();
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
