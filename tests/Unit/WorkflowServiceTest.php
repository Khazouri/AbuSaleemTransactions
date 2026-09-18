<?php

namespace Tests\Unit;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_happy_path_moves_a_request_from_stage_one_to_twelve_with_complete_logs(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Diagram-alignment redesign (see AGENT_NOTES.md): the walk now
        // opens with the three new front-half stages before it reaches the
        // stage that used to be first in this test. Those three hops are
        // manager-gated, not role-gated, so a submitter+manager pair is
        // wired up ($employee->manager_id) exactly like production data.
        $employee = $this->userWithRole('R01');
        $manager = User::factory()->create(['is_active' => true]);
        $employee->manager_id = $manager->id;
        $employee->save();

        // Stage 86 added R09 (أمين سر اللجنة): the two hops into the committee
        // are the secretary's now, not R02's and R05's.
        $actors = collect(['R02', 'R03', 'R05', 'R06', 'R07', 'R09'])
            ->mapWithKeys(fn (string $roleCode) => [
                $roleCode => $this->userWithRole($roleCode),
            ]);
        $actors['R01'] = $employee;
        $actors['MANAGER'] = $manager;

        $requestRecord = $this->newRequest(createdByUserId: $employee->id);
        $service = app(WorkflowService::class);

        $steps = [
            ['receive_from_municipality', 'direct_manager_review', 'submit', 'R01', 'in_review'],
            ['direct_manager_review', 'administrative_routing', 'forward', 'MANAGER', 'in_review'],
            ['administrative_routing', 'receive_and_register', 'route_to_hr', 'MANAGER', 'routed_to_hr'],
            // The قيد: accepting the file is what registers it, so this hop
            // lands on Art. 38's code 06 (registered, مستوفية ومقيدة) and is
            // what grants the رقم إشاري. The approve hop below re-stamps the
            // same code deliberately — see WorkflowTransitionSeeder for why,
            // and UnifiedNumberingTest for the number not changing there.
            ['receive_and_register', 'requirements_check', 'register', 'R05', 'registered'],
            ['requirements_check', 'reviewer_review', 'approve', 'R02', 'registered'],
            ['reviewer_review', 'observations', 'forward', 'R02', 'in_review'],
            // Stage 57 collapsed the old two-hop observations ->
            // ministry_endorsement -> forward_to_committee into one; Stage 86
            // then moved both remaining hops into the committee to R09
            // (أمين سر اللجنة), from R02 and R05 respectively.
            ['observations', 'forward_to_committee', 'forward', 'R09', 'ready'],
            ['forward_to_committee', 'receive_from_committee', 'forward', 'R09', 'in_meeting'],
            ['receive_from_committee', 'approval_by_authority', 'approve', 'R03', 'awaiting_municipal_approval'],
            ['approval_by_authority', 'local_governance_ministry', 'approve', 'R05', 'awaiting_central_approval'],
            // Stage 57 removed competent_authority: ministry approval is now
            // the literal last gate before final_approval_archiving.
            ['local_governance_ministry', 'final_approval_archiving', 'approve', 'R06', 'final_approved'],
            ['final_approval_archiving', 'final_approval_archiving', 'approve', 'R07', 'in_execution'],
        ];

        foreach ($steps as [$from, $to, $action, $roleCode, $statusCode]) {
            $requestRecord = $service->transition(
                $requestRecord,
                $action,
                $actors[$roleCode],
                signaturePath: $action === 'approve' ? "signatures/test-{$from}.png" : null,
            );

            $this->assertSame($to, $requestRecord->currentStage->code);
            $this->assertSame($statusCode, $requestRecord->status->code);
            $this->assertDatabaseHas('request_stage_logs', [
                'request_id' => $requestRecord->id,
                'from_stage_id' => WorkflowStage::where('code', $from)->value('id'),
                'to_stage_id' => WorkflowStage::where('code', $to)->value('id'),
                'action' => $action,
                'acted_by_user_id' => $actors[$roleCode]->id,
            ]);
        }

        $this->assertSame('final_approval_archiving', $requestRecord->currentStage->code);
        $this->assertSame('in_execution', $requestRecord->status->code);
        $this->assertCount(12, $requestRecord->stageLogs);
        $this->assertCount(12, $requestRecord->statusHistory);
        $this->assertSame(
            [
                'direct_manager_review', 'administrative_routing', 'receive_and_register',
                'requirements_check', 'reviewer_review', 'observations',
                'forward_to_committee', 'receive_from_committee', 'approval_by_authority',
                'local_governance_ministry', 'final_approval_archiving',
                'final_approval_archiving',
            ],
            $requestRecord->stageLogs()
                ->with('toStage')
                ->orderBy('id')
                ->get()
                ->pluck('toStage.code')
                ->all(),
        );
        // The four new front-half hops (submit/forward/route_to_hr/register)
        // are none of them action `approve`, so they write no Approval ledger
        // row. Stage 57 removed the competent_authority checkpoint, so the
        // chain is 5 approvals now, not 6 — R07 only clicks once (the final
        // self-loop), not twice (authority then final).
        $this->assertSame(range(1, 5), $requestRecord->approvals()->orderBy('id')->pluck('level')->all());
        $this->assertSame(
            ['R02', 'R03', 'R05', 'R06', 'R07'],
            $requestRecord->approvals()->with('role')->orderBy('id')->get()->pluck('role.code')->all(),
        );
    }

    public function test_transition_rejects_an_actor_without_the_configured_role_without_mutating_state(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Diagram-alignment redesign: the outbound action from stage one is
        // now `submit` (R01/R08), not `forward` — an R02 actor has the wrong
        // role for it, which is exactly the case this test wants.
        $requestRecord = $this->newRequest();
        $reviewer = $this->userWithRole('R02');

        try {
            app(WorkflowService::class)->transition($requestRecord, 'submit', $reviewer);
            $this->fail('The transition should reject an actor without R01.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame(
                'لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.',
                $exception->getMessage(),
            );
        }

        $requestRecord->refresh();

        $this->assertSame('receive_from_municipality', $requestRecord->currentStage->code);
        $this->assertSame('new', $requestRecord->status->code);
        $this->assertDatabaseCount('request_stage_logs', 0);
        $this->assertDatabaseCount('request_status_history', 0);
    }

    public function test_seeded_happy_path_contains_one_ordered_rule_for_each_forward_stage(): void
    {
        $this->seed(DatabaseSeeder::class);

        $rules = WorkflowTransition::query()
            ->where('is_exception', false)
            ->with(['fromStage', 'toStage', 'requiredRole'])
            ->get();

        // Diagram-alignment redesign: this is no longer literally "one rule
        // per stage". administrative_routing's only outbound moves are its
        // three route_to_* branches, all modelled as exceptions (see
        // WorkflowTransitionSeeder), so it contributes zero non-exception
        // rows; receive_and_register converges through three role-scoped
        // `register` rows instead of one. 11 (the old total) - 1
        // (administrative_routing) + 3 (register) + 2 (submit, and the new
        // manager-gated forward into administrative_routing) = 15. Stage 57
        // then removed two whole stages (ministry_endorsement,
        // competent_authority), each contributing exactly one row: 15 - 2 = 13.
        $this->assertCount(13, $rules);
        $this->assertFalse($rules->contains('is_exception', true));
        $this->assertFalse($rules->contains('requires_comment', true));

        $countsByFromStageCode = $rules->groupBy('fromStage.code')->map->count();
        foreach ([
            'receive_from_municipality' => 1,
            'direct_manager_review' => 1,
            'receive_and_register' => 3,
            'requirements_check' => 1,
            'reviewer_review' => 1,
            'observations' => 1,
            'forward_to_committee' => 1,
            'receive_from_committee' => 1,
            'approval_by_authority' => 1,
            'local_governance_ministry' => 1,
            'final_approval_archiving' => 1,
        ] as $stageCode => $expectedCount) {
            $this->assertSame($expectedCount, $countsByFromStageCode->get($stageCode, 0), "stage {$stageCode}");
        }
        $this->assertArrayNotHasKey('administrative_routing', $countsByFromStageCode->all());
        // Stage 57 — both removed stages must be gone entirely, not merely
        // unreferenced by a non-exception rule.
        $this->assertArrayNotHasKey('ministry_endorsement', $countsByFromStageCode->all());
        $this->assertArrayNotHasKey('competent_authority', $countsByFromStageCode->all());

        // The three `register` rows are what makes the routing enforceable:
        // one per legitimate receiving role, all converging on the same
        // destination stage.
        $registerRules = $rules->where('action', 'register')->values();
        $this->assertCount(3, $registerRules);
        $this->assertEqualsCanonicalizing(['R05', 'R09', 'R10'], $registerRules->pluck('requiredRole.code')->all());
        $this->assertTrue($registerRules->every(fn (WorkflowTransition $rule) => $rule->toStage->code === 'requirements_check'));
    }

    public function test_each_seeded_exception_path_moves_to_the_expected_stage_status_and_records_its_reason(): void
    {
        $this->seed(DatabaseSeeder::class);

        $reviewer = $this->userWithRole('R02');
        // Stage 86 — cancelling at forward_to_committee followed the forwarding
        // hop from R05 to R09, per that seeder's own rule that cancellation
        // belongs to the role responsible for moving the stage.
        $secretary = $this->userWithRole('R09');
        $service = app(WorkflowService::class);
        $paths = [
            ['requirements_check', 'return_missing_docs', $reviewer, 'receive_from_municipality', 'incomplete', 'المستند المالي غير مرفق.'],
            ['reviewer_review', 'reject_review', $reviewer, 'requirements_check', 'rejected', 'الطلب لا تطابق اللائحة.'],
            // R02 keeps this one: the study is still its work, and sending the
            // file back is still its call — only the handover onward moved.
            ['observations', 'request_edit', $reviewer, 'reviewer_review', 'returned', 'يرجى تصحيح بيانات القرار.'],
            ['forward_to_committee', 'cancel', $secretary, 'forward_to_committee', 'cancelled', 'أُلغي الطلب بناءً على كتاب رسمي.'],
        ];

        foreach ($paths as [$from, $action, $actor, $to, $status, $reason]) {
            $requestRecord = $this->newRequest($from, 'in_review');
            $requestRecord = $service->transition($requestRecord, $action, $actor, $reason);

            $this->assertSame($to, $requestRecord->currentStage->code);
            $this->assertSame($status, $requestRecord->status->code);
            $this->assertDatabaseHas('request_stage_logs', [
                'request_id' => $requestRecord->id,
                'from_stage_id' => WorkflowStage::where('code', $from)->value('id'),
                'to_stage_id' => WorkflowStage::where('code', $to)->value('id'),
                'action' => $action,
                'comment' => $reason,
                'acted_by_user_id' => $actor->id,
            ]);
            $this->assertDatabaseHas('request_status_history', [
                'request_id' => $requestRecord->id,
                'to_status_id' => RequestStatus::where('code', $status)->value('id'),
                'reason' => $reason,
                'changed_by_user_id' => $actor->id,
            ]);
        }
    }

    public function test_exception_requires_a_non_blank_reason_without_mutating_the_request(): void
    {
        $this->seed(DatabaseSeeder::class);

        $requestRecord = $this->newRequest('requirements_check', 'in_review');
        $reviewer = $this->userWithRole('R02');

        try {
            app(WorkflowService::class)->transition($requestRecord, 'return_missing_docs', $reviewer, '   ');
            $this->fail('The exception transition should require a reason.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('يجب إدخال سبب لتنفيذ هذا الإجراء.', $exception->getMessage());
        }

        $requestRecord->refresh();
        $this->assertSame('requirements_check', $requestRecord->currentStage->code);
        $this->assertSame('in_review', $requestRecord->status->code);
        $this->assertDatabaseCount('request_stage_logs', 0);
        $this->assertDatabaseCount('request_status_history', 0);
    }

    public function test_cancelled_request_is_terminal_and_exposes_no_further_actions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $reviewer = $this->userWithRole('R02');
        $service = app(WorkflowService::class);
        $requestRecord = $service->transition(
            $this->newRequest('requirements_check', 'in_review'),
            'cancel',
            $reviewer,
            'ألغي الطلب بطلب الجهة.',
        );

        $this->assertTrue($service->availableActions($requestRecord, $reviewer)->isEmpty());

        try {
            $service->transition($requestRecord, 'approve', $reviewer);
            $this->fail('A cancelled request must not re-enter the workflow.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('لا يمكن تنفيذ إجراء سير عمل على طلب ملغى أو خرج إلى التنفيذ أو أُغلق.', $exception->getMessage());
        }

        $this->assertDatabaseCount('request_stage_logs', 1);
        $this->assertDatabaseCount('request_status_history', 1);
    }

    public function test_exception_rules_are_seeded_for_corrections_and_every_open_stage_can_be_cancelled(): void
    {
        $this->seed(DatabaseSeeder::class);

        $correctiveRules = WorkflowTransition::query()
            ->whereIn('action', ['return_missing_docs', 'reject_review', 'request_edit'])
            ->with(['fromStage', 'toStage'])
            ->orderBy('from_stage_id')
            ->get();

        // Diagram-alignment redesign: these three corrective rules sit on
        // pre-existing stages that were only renumbered (requirements_check,
        // reviewer_review, observations are now order_no 5/6/7, not 2/3/4);
        // stage IDENTITY (and so which stage each rule targets) is unchanged.
        $this->assertCount(3, $correctiveRules);
        $this->assertSame([5, 6, 7], $correctiveRules->pluck('fromStage.order_no')->all());
        $this->assertSame([1, 5, 6], $correctiveRules->pluck('toStage.order_no')->all());
        $this->assertTrue($correctiveRules->every('is_exception', true));
        $this->assertTrue($correctiveRules->every('requires_comment', true));

        $cancelRules = WorkflowTransition::query()
            ->where('action', 'cancel')
            ->with(['fromStage', 'toStage'])
            ->get();

        // Diagram-alignment redesign: 11 (unchanged) + the two new
        // manager-gated stages (direct_manager_review, administrative_routing,
        // one cancel row each) + receive_and_register's three role-scoped
        // cancel rows (any of its three legitimate receiving roles may
        // cancel, unlike `register` this isn't status-gated — see
        // WorkflowTransitionSeeder) = 16. receive_and_register's order_no
        // (4) appears three times, once per role-scoped row. Stage 57 then
        // removed two of those 11 base stages (ministry_endorsement,
        // competent_authority): 16 - 2 = 14, and every surviving stage from
        // forward_to_committee onward shifted down one order_no.
        $this->assertCount(14, $cancelRules);
        $this->assertEqualsCanonicalizing(
            [1, 2, 3, 4, 4, 4, 5, 6, 7, 8, 9, 10, 11, 12],
            $cancelRules->pluck('fromStage.order_no')->all(),
        );
        $this->assertTrue($cancelRules->every(
            fn (WorkflowTransition $rule) => $rule->fromStage->is($rule->toStage)
                && $rule->is_exception
                && $rule->requires_comment,
        ));
    }

    public function test_low_grade_request_skips_ministry_but_preserves_the_other_approval_levels(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actors = collect(['R02', 'R03', 'R05', 'R07', 'R09'])
            ->mapWithKeys(fn (string $roleCode) => [
                $roleCode => $this->userWithRole($roleCode),
            ]);
        $service = app(WorkflowService::class);
        // Diagram-alignment redesign: this test's subject is the decision-grade
        // ministry-skip branch deep in the unchanged back half, not the new
        // front-half hops — start it past those (at requirements_check,
        // matching what used to be the effective starting point after the
        // very first `forward` step below, which is why that step is gone).
        // Stage 57 collapsed observations -> ministry_endorsement ->
        // forward_to_committee into one hop, so only one `forward` step into
        // the committee remains (forward_to_committee ->
        // receive_from_committee) — not two. Stage 86 then made both hops into
        // the committee R09's (أمين سر اللجنة), leaving R02 the study half of
        // the chain only.
        $requestRecord = $this->newRequest(stageCode: 'requirements_check', statusCode: 'in_review', decisionGrade: 9);

        foreach ([
            ['approve', 'R02'],
            ['forward', 'R02'],
            ['forward', 'R09'],
            ['forward', 'R09'],
            ['approve', 'R03'],
        ] as [$action, $role]) {
            $requestRecord = $service->transition(
                $requestRecord,
                $action,
                $actors[$role],
                signaturePath: $action === 'approve' ? 'signatures/test.png' : null,
            );
        }

        // Stage 57 — the admin-manager's own approve is what bypasses
        // ministry now: it lands directly at final_approval_archiving (not
        // competent_authority, which no longer exists) with status
        // final_approved, since nothing else is left pending.
        $requestRecord = $service->transition(
            $requestRecord,
            'approve',
            $actors['R05'],
            signaturePath: 'signatures/admin-manager.png',
        );
        $this->assertSame('final_approval_archiving', $requestRecord->currentStage->code);
        $this->assertSame('final_approved', $requestRecord->status->code);

        // One R07 self-loop click closes it out — not two (authority, then
        // final), since there is no longer an intermediate authority stage.
        $requestRecord = $service->transition(
            $requestRecord,
            'approve',
            $actors['R07'],
            signaturePath: 'signatures/final.png',
        );

        $this->assertSame('in_execution', $requestRecord->status->code);
        $this->assertSame([1, 2, 3, 5], $requestRecord->approvals()->orderBy('id')->pluck('level')->all());
        $this->assertDatabaseMissing('approvals', [
            'request_id' => $requestRecord->id,
            'level' => 4,
        ]);
    }

    public function test_approval_transition_requires_a_signature_path_before_mutating_state(): void
    {
        $this->seed(DatabaseSeeder::class);

        $requestRecord = $this->newRequest('requirements_check', 'in_review');
        $reviewer = $this->userWithRole('R02');

        try {
            app(WorkflowService::class)->transition($requestRecord, 'approve', $reviewer);
            $this->fail('An approval must not be recorded without signature evidence.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame(
                'يجب إرفاق التوقيع الإلكتروني لإتمام الاعتماد.',
                $exception->getMessage(),
            );
        }

        $this->assertSame('requirements_check', $requestRecord->refresh()->currentStage->code);
        $this->assertDatabaseCount('approvals', 0);
        $this->assertDatabaseCount('request_stage_logs', 0);
    }

    public function test_actor_cannot_skip_the_admin_manager_checkpoint(): void
    {
        $this->seed(DatabaseSeeder::class);

        $requestRecord = $this->newRequest('approval_by_authority', 'decided');
        $ministry = $this->userWithRole('R06');

        try {
            app(WorkflowService::class)->transition($requestRecord, 'approve', $ministry);
            $this->fail('Ministry must not be able to approve before the admin manager.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame(
                'لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame('approval_by_authority', $requestRecord->refresh()->currentStage->code);
    }

    /**
     * Stage 64, Track J — the appeal_redo re-entry mechanism. Not driven by
     * any workflow_transitions row, so both guards (the fixed exclusion
     * list and the backward-only order check) live entirely inside the
     * method itself; see AppealOutcomeExecutor, its only caller.
     */
    public function test_reopen_at_stage_refuses_an_excluded_target(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R02');
        $requestRecord = $this->newRequest('receive_from_committee', 'decided');
        $target = WorkflowStage::where('code', 'receive_and_register')->firstOrFail();

        try {
            app(WorkflowService::class)->reopenAtStage($requestRecord, $target, $actor, 'سبب');
            $this->fail('receive_and_register must be refused as a redo target.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame('لا يمكن إعادة الإجراءات إلى هذه المرحلة.', $exception->getMessage());
        }

        $this->assertSame('receive_from_committee', $requestRecord->refresh()->currentStage->code);
    }

    public function test_reopen_at_stage_refuses_a_target_stage_ordered_after_the_current_one(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R02');
        $requestRecord = $this->newRequest('receive_from_committee', 'decided');
        $target = WorkflowStage::where('code', 'final_approval_archiving')->firstOrFail();

        try {
            app(WorkflowService::class)->reopenAtStage($requestRecord, $target, $actor, 'سبب');
            $this->fail('A forward redo target must be refused.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame(
                'يجب أن تكون مرحلة إعادة الإجراءات سابقة لمرحلة الطلب الحالية أو مساوية لها.',
                $exception->getMessage(),
            );
        }

        $this->assertSame('receive_from_committee', $requestRecord->refresh()->currentStage->code);
    }

    public function test_reopen_at_stage_moves_backward_and_writes_a_full_audit_trail(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R02');
        $requestRecord = $this->newRequest('receive_from_committee', 'decided');
        $fromStageId = $requestRecord->current_stage_id;
        $target = WorkflowStage::where('code', 'reviewer_review')->firstOrFail();

        $moved = app(WorkflowService::class)->reopenAtStage($requestRecord, $target, $actor, 'سبب إعادة الإجراءات');

        $this->assertSame($target->id, $moved->current_stage_id);
        $this->assertSame('reopened_by_appeal', $moved->status->code);

        $this->assertDatabaseHas('request_stage_logs', [
            'request_id' => $requestRecord->id,
            'from_stage_id' => $fromStageId,
            'to_stage_id' => $target->id,
            'action' => 'appeal_redo',
            'comment' => 'سبب إعادة الإجراءات',
        ]);
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $requestRecord->id,
            'to_status_id' => RequestStatus::where('code', 'reopened_by_appeal')->value('id'),
        ]);
    }

    /**
     * Stage 66, Track J generalizes reopenAtStage() for the non-appeal
     * re-presentation path (RequestController::reopen()) — an early
     * cancellation reopened for re-presentation may need to move FORWARD
     * (resume past where it stopped), unlike appeal_redo's "undo a specific
     * defect" framing, which is always backward relative to an
     * already-decided request. `enforceBackwardOnly: false` is what makes
     * that forward move possible; the custom $action/$statusCode are what
     * keep it out of `reopened_by_appeal`'s vocabulary.
     */
    public function test_reopen_at_stage_allows_a_forward_move_with_a_custom_action_and_status_when_backward_only_is_disabled(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actor = $this->userWithRole('R02');
        $requestRecord = $this->newRequest('direct_manager_review', 'cancelled');
        $fromStageId = $requestRecord->current_stage_id;
        $target = WorkflowStage::where('code', 'requirements_check')->firstOrFail();

        $moved = app(WorkflowService::class)->reopenAtStage(
            $requestRecord,
            $target,
            $actor,
            'سبب إعادة العرض',
            action: 'reopen',
            statusCode: 'reopened_for_representation',
            enforceBackwardOnly: false,
        );

        $this->assertSame($target->id, $moved->current_stage_id);
        $this->assertSame('reopened_for_representation', $moved->status->code);

        $this->assertDatabaseHas('request_stage_logs', [
            'request_id' => $requestRecord->id,
            'from_stage_id' => $fromStageId,
            'to_stage_id' => $target->id,
            'action' => 'reopen',
            'comment' => 'سبب إعادة العرض',
        ]);
    }

    private function newRequest(
        string $stageCode = 'receive_from_municipality',
        string $statusCode = 'new',
        int $decisionGrade = 10,
        ?int $createdByUserId = null,
    ): Request {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'اختبار المسار الأساسي',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'submitted_at' => now(),
            'decision_grade' => $decisionGrade,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
