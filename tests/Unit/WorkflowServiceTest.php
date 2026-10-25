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

        // Stage 86 gave the two hops into the committee to R09 (أمين سر
        // اللجنة) and Stage 96 gave them back to R02 (مقرر اللجنة), because
        // [D]'s الملحق السادس has no أمين سر اللجنة column. Stage 87 added R12
        // (مدير إدارة الموارد البشرية): the receive_and_register hop is theirs,
        // not R05's — R05 keeps only its later approval_by_authority duty,
        // unaffected by any of it.
        $actors = collect(['R02', 'R05', 'R06', 'R07', 'R12'])
            ->mapWithKeys(fn (string $roleCode) => [
                $roleCode => $this->userWithRole($roleCode),
            ]);
        $actors['R01'] = $employee;
        $actors['MANAGER'] = $manager;

        $requestRecord = $this->newRequest(createdByUserId: $employee->id);
        $service = app(WorkflowService::class);

        $steps = [
            ['receive_from_municipality', 'direct_manager_review', 'submit', 'R01', 'in_review'],
            // One-click approval (2026-09-26): the manager's forward lands on
            // HR directly; administrative_routing is no longer on the path.
            ['direct_manager_review', 'receive_and_register', 'forward', 'MANAGER', 'routed_to_hr'],
            // Art. 38's code 04 (in_review, تحت فحص الاكتمال): the file has
            // been delivered to be checked, not yet checked. Passing the check
            // is code 06 (registered, مستوفية ومقيدة) on the approve hop below,
            // which is also where Art. 20 grants the رقم إشاري (Stage 97).
            // Stage 87 — this is the HR route, so the registrar is R12, not
            // R05, which keeps only its later approval_by_authority duty.
            ['receive_and_register', 'requirements_check', 'register', 'R12', 'in_review'],
            // Stage 102 — the مقرر's approve lands straight on the committee's
            // pending list; the three R02 forward hops through stages 6–8 are gone.
            ['requirements_check', 'receive_from_committee', 'approve', 'R02', 'registered'],
            // 2026-10-18 — the committee meets on paper and the مقرر enters
            // its recommendation, so the committee's approve is R02's.
            ['receive_from_committee', 'approval_by_authority', 'approve', 'R02', 'awaiting_municipal_approval'],
            // 2026-10-17 — جهة الاعتماد is one step: with no authority picked
            // (or `dean`), العميد approves here and the file is fully approved.
            // 2026-10-25 — العميد's approve waits for the مقرر's confirmation.
            ['approval_by_authority', 'rapporteur_decision_review', 'approve', 'R07', 'authority_approved'],
            ['rapporteur_decision_review', 'final_approval_archiving', 'confirm_decision', 'R02', 'final_approved'],
            // 2026-10-18 — no execution step: final_approved waits for the
            // two archives, which close the file (RequestClosureService).
        ];

        foreach ($steps as [$from, $to, $action, $roleCode, $statusCode]) {
            $requestRecord = $service->transition(
                $requestRecord,
                $action,
                $actors[$roleCode],
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
        $this->assertSame('final_approved', $requestRecord->status->code);
        $this->assertCount(7, $requestRecord->stageLogs);
        $this->assertCount(7, $requestRecord->statusHistory);
        $this->assertSame(
            [
                'direct_manager_review', 'receive_and_register',
                'requirements_check', 'receive_from_committee', 'approval_by_authority',
                'rapporteur_decision_review', 'final_approval_archiving',
            ],
            $requestRecord->stageLogs()
                ->with('toStage')
                ->orderBy('id')
                ->get()
                ->pluck('toStage.code')
                ->all(),
        );
        // The three front-half hops (submit/forward/register)
        // are none of them action `approve`, so they write no Approval ledger
        // row. Stage 57 removed the competent_authority checkpoint, so the
        // chain is 5 approvals now, not 6 — R07 only clicks once (the final
        // self-loop), not twice (authority then final). 2026-10-17 — the dean's
        // file never reaches the ministry, so level 4 is absent.
        // 2026-10-18 — and the execution self-loop (level 5) is gone.
        $this->assertSame([1, 2, 3], $requestRecord->approvals()->orderBy('id')->pluck('level')->all());
        $this->assertSame(
            ['R02', 'R02', 'R07'],
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
        // per stage". administrative_routing's only outbound move is its
        // route_to_hr branch, modelled as an exception (see
        // WorkflowTransitionSeeder), so it contributes zero non-exception
        // rows. 11 (the old total) - 1 (administrative_routing) + 2 (submit,
        // and the new manager-gated forward into administrative_routing) = 12.
        // Stage 57 then removed two whole stages (ministry_endorsement,
        // competent_authority), each contributing exactly one row: 12 - 2 = 10,
        // and Stage 96 left receive_and_register with a single `register` row
        // (it had three, one per receiving role): 10 + 1 = 11. Stage 102 took
        // reviewer_review, observations and forward_to_committee off the path,
        // one row each: 11 - 3 = 8. 2026-10-18 dropped the execution
        // self-loop and gave each authority a `reject`: 8 - 1 + 2 = 9.
        // 2026-10-25 added the مقرر's two `confirm_decision` rows: 9 + 2 = 11.
        $this->assertCount(11, $rules);
        $this->assertFalse($rules->contains('is_exception', true));
        $this->assertFalse($rules->contains('requires_comment', true));

        $countsByFromStageCode = $rules->groupBy('fromStage.code')->map->count();
        foreach ([
            'receive_from_municipality' => 1,
            'direct_manager_review' => 1,
            'receive_and_register' => 1,
            'requirements_check' => 1,
            'receive_from_committee' => 1,
            'approval_by_authority' => 2,
            'local_governance_ministry' => 2,
            'rapporteur_decision_review' => 2,
            'final_approval_archiving' => 0,
        ] as $stageCode => $expectedCount) {
            $this->assertSame($expectedCount, $countsByFromStageCode->get($stageCode, 0), "stage {$stageCode}");
        }
        $this->assertArrayNotHasKey('administrative_routing', $countsByFromStageCode->all());
        // Stage 57 — both removed stages must be gone entirely, not merely
        // unreferenced by a non-exception rule.
        $this->assertArrayNotHasKey('ministry_endorsement', $countsByFromStageCode->all());
        $this->assertArrayNotHasKey('competent_authority', $countsByFromStageCode->all());
        // Stage 102 — no rule of any kind leaves the three retired stages, so a
        // file can neither reach nor sit on them.
        $this->assertSame(0, WorkflowTransition::query()
            ->whereHas('fromStage', fn ($stage) => $stage->whereIn('code', ['reviewer_review', 'observations', 'forward_to_committee']))
            ->count());
        $this->assertSame('receive_from_committee', $rules->firstWhere('fromStage.code', 'requirements_check')->toStage->code);

        // Stage 96 — ONE `register` row, not three. R10 and R09 have no
        // column in [D] Appendix 6, and the receiving party it does name is
        // الموارد البشرية, which is R12 (Stage 87 put it there, replacing R05).
        // The status gate on that single row is what still makes routing
        // enforceable: an unrouted file cannot be registered.
        $registerRules = $rules->where('action', 'register')->values();
        $this->assertCount(1, $registerRules);
        $this->assertSame(['R12'], $registerRules->pluck('requiredRole.code')->all());
        $this->assertTrue($registerRules->every(fn (WorkflowTransition $rule) => $rule->toStage->code === 'requirements_check'));
    }

    public function test_each_seeded_exception_path_moves_to_the_expected_stage_status_and_records_its_reason(): void
    {
        $this->seed(DatabaseSeeder::class);

        $reviewer = $this->userWithRole('R02');
        $head = $this->userWithRole('R03');
        $service = app(WorkflowService::class);
        // Stage 102 — reject_review and request_edit went with their stages;
        // the committee's return_to_study now lands on the مقرر's own
        // requirements_check, whose approve puts the file back on the list.
        $paths = [
            ['requirements_check', 'return_missing_docs', $reviewer, 'receive_from_municipality', 'incomplete', 'المستند المالي غير مرفق.'],
            ['receive_from_committee', 'return_to_study', $head, 'requirements_check', 'returned', 'يلزم استكمال الدراسة.'],
            ['receive_from_committee', 'cancel', $head, 'receive_from_committee', 'cancelled', 'أُلغي الطلب بناءً على كتاب رسمي.'],
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

        // Stage 102 — reject_review (stage 6) and request_edit (stage 7) went
        // with their stages; only requirements_check's return survives.
        $this->assertCount(1, $correctiveRules);
        $this->assertSame([5], $correctiveRules->pluck('fromStage.order_no')->all());
        $this->assertSame([1], $correctiveRules->pluck('toStage.order_no')->all());
        $this->assertTrue($correctiveRules->every('is_exception', true));
        $this->assertTrue($correctiveRules->every('requires_comment', true));

        $cancelRules = WorkflowTransition::query()
            ->where('action', 'cancel')
            ->with(['fromStage', 'toStage'])
            ->get();

        // Diagram-alignment redesign: 11 (unchanged) + the two new
        // manager-gated stages (direct_manager_review, administrative_routing,
        // one cancel row each) + receive_and_register's own row = 14. Stage 57
        // then removed two of those 11 base stages (ministry_endorsement,
        // competent_authority): 14 - 2 = 12, and every surviving stage from
        // forward_to_committee onward shifted down one order_no. Exactly one
        // cancel row per stage again after Stage 96 — receive_and_register
        // briefly had three, one per receiving role, and now has only R12's.
        // Stage 102 — stages 6, 7 and 8 are off the path: 12 - 3 = 9.
        // 2026-10-25 — the مقرر's review (12) has no cancel: the authority has
        // already decided. Archiving moved to 13.
        $this->assertCount(9, $cancelRules);
        $this->assertEqualsCanonicalizing(
            [1, 2, 3, 4, 5, 9, 10, 11, 13],
            $cancelRules->pluck('fromStage.order_no')->all(),
        );
        // The two manager-gated cancels take an optional note (2026-10-07);
        // every other cancel still demands a reason.
        $this->assertTrue($cancelRules->every(
            fn (WorkflowTransition $rule) => $rule->fromStage->is($rule->toStage)
                && $rule->is_exception
                && $rule->requires_comment === ! $rule->requires_submitter_manager,
        ));
    }

    /**
     * 2026-10-17 — the minister's file is the mirror of the dean's: the
     * committee's approve goes straight to the ministry stage, whatever the
     * grade (the threshold only suggests), and the dean's stage is skipped.
     * Replaces Stage 57's grade-driven ministry bypass.
     */
    public function test_a_minister_file_skips_the_dean_stage(): void
    {
        $this->seed(DatabaseSeeder::class);

        $actors = collect(['R02', 'R06', 'R07'])
            ->mapWithKeys(fn (string $roleCode) => [
                $roleCode => $this->userWithRole($roleCode),
            ]);
        $service = app(WorkflowService::class);
        $requestRecord = $this->newRequest(stageCode: 'requirements_check', statusCode: 'in_review', decisionGrade: 1);

        $requestRecord = $service->transition($requestRecord, 'approve', $actors['R02']);
        $requestRecord->update(['approving_authority' => 'minister']);
        $requestRecord = $service->transition($requestRecord, 'approve', $actors['R02']);
        $this->assertSame('local_governance_ministry', $requestRecord->currentStage->code);
        $this->assertSame('awaiting_central_approval', $requestRecord->status->code);

        // 2026-10-25 — via the مقرر's confirmation of الوزير's approve.
        $requestRecord = $service->transition($requestRecord, 'approve', $actors['R06']);
        $requestRecord = $service->transition($requestRecord, 'confirm_decision', $actors['R02']);
        $this->assertSame('final_approval_archiving', $requestRecord->currentStage->code);
        $this->assertSame('final_approved', $requestRecord->status->code);

        // 2026-10-18 — no execution step after it, on either authority's files.
        $this->assertSame([1, 2, 4], $requestRecord->approvals()->orderBy('id')->pluck('level')->all());
    }

    /**
     * Signatures have been removed from the system — approving is a plain
     * confirmation, so a bare `transition('approve', ...)` call with no
     * signature evidence must succeed and still write the approval ledger
     * row.
     */
    public function test_approval_transition_succeeds_with_no_signature_evidence(): void
    {
        $this->seed(DatabaseSeeder::class);

        $requestRecord = $this->newRequest('requirements_check', 'in_review');
        $reviewer = $this->userWithRole('R02');

        $requestRecord = app(WorkflowService::class)->transition($requestRecord, 'approve', $reviewer);

        $this->assertSame('receive_from_committee', $requestRecord->currentStage->code);
        $this->assertSame('registered', $requestRecord->status->code);
        $this->assertDatabaseCount('approvals', 1);
        $this->assertDatabaseCount('request_stage_logs', 1);
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
        // Stage 102 — reviewer_review is off the path and refused as a target.
        $target = WorkflowStage::where('code', 'requirements_check')->firstOrFail();

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
