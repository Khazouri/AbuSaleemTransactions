<?php

namespace Tests\Feature;

use App\Models\RequestStatus;
use App\Models\Role;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use App\Models\WorkflowVersion;
use App\Services\WorkflowVersionValidator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Workflow designer — slice 3. What stops a draft being published: a step
 * no path reaches, a step a file can't leave towards an end, and a path to
 * the committee that skips the قيد. Built-in steps nothing reaches (6–8 and
 * administrative_routing) are history, not mistakes, and stay unreported — the
 * admin may delete them (slice 5). Slice 5 adds what the code itself depends on:
 * the anchors stay reachable, the three rules the code resolves by stage and
 * action exist once, and a file reaches each stage with a status its exits take.
 */
class WorkflowValidatorTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowVersion $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->draft = Workflow::standard()->publishedVersion->cloneAsDraft();
    }

    public function test_an_unchanged_copy_of_standard_has_no_errors(): void
    {
        $this->assertSame([], $this->report()['errors']);
    }

    public function test_a_custom_step_nothing_reaches_is_unreachable(): void
    {
        $this->customStep('custom_island');

        $this->assertSame([['unreachable', 'custom_island']], $this->errorCodes());
    }

    public function test_a_step_whose_only_rule_loops_on_itself_is_stranded(): void
    {
        $this->customStep('custom_loop');
        $this->rule('direct_manager_review', 'custom_loop', 'custom_into_loop');
        $this->rule('custom_loop', 'custom_loop', 'custom_stay');

        $this->assertSame([['stranded', 'custom_loop']], $this->errorCodes());
    }

    public function test_reaching_the_committee_without_registration_is_an_error(): void
    {
        WorkflowTransition::query()
            ->where('from_stage_id', $this->draft->stage('direct_manager_review')->id)
            ->where('action', 'forward')
            ->update(['to_stage_id' => $this->draft->stage('receive_from_committee')->id]);

        $this->assertContains(['committee_unregistered', 'receive_from_committee'], $this->errorCodes());
    }

    public function test_a_custom_outcome_naming_a_role_nobody_holds_is_a_warning(): void
    {
        $this->customStep('custom_audit');
        $this->rule('direct_manager_review', 'custom_audit', 'custom_send', 'R11');
        $this->rule('custom_audit', 'receive_and_register', 'custom_done', 'R11', status: 'routed_to_hr');

        $report = $this->report();
        $this->assertSame([], $report['errors']);
        $this->assertSame(['role_without_holder'], collect($report['warnings'])->pluck('code')->unique()->values()->all());
    }

    public function test_a_change_to_the_source_after_cloning_is_a_warning(): void
    {
        $this->travel(1)->minutes();
        Workflow::standard()->publishedVersion->stage('requirements_check')->update(['target_days_max' => 99]);

        $this->assertContains('source_changed', collect($this->report()['warnings'])->pluck('code')->all());
    }

    public function test_a_checked_outcome_on_a_step_with_no_checklist_is_an_error(): void
    {
        $this->customStep('custom_audit');
        $this->rule('direct_manager_review', 'custom_audit', 'custom_send', 'R05');
        $this->rule('custom_audit', 'receive_and_register', 'custom_done', 'R05', status: 'routed_to_hr');
        // An unchecked return, so the step keeps a way out once it has a checklist (checklist_no_way_out).
        $this->rule('custom_audit', 'direct_manager_review', 'custom_return', 'R05');
        WorkflowTransition::where('action', 'custom_done')->update(['requires_checks' => true]);

        $this->assertSame([['checks_without_checklist', 'custom_audit']], $this->errorCodes());

        WorkflowStage::where('code', 'custom_audit')->update(['checklist' => json_encode([
            ['key' => 'cq', 'type' => 'attestation', 'label_ar' => 'أقر'],
        ])]);
        $this->assertSame([], $this->errorCodes());
    }

    public function test_a_checklist_step_whose_every_exit_waits_on_it_is_an_error(): void
    {
        $this->customStep('custom_audit');
        $this->rule('direct_manager_review', 'custom_audit', 'custom_send', 'R05');
        $this->rule('custom_audit', 'receive_and_register', 'custom_done', 'R05', status: 'routed_to_hr');
        // A hold that never leaves the step is no way out.
        $this->rule('custom_audit', 'custom_audit', 'custom_hold', 'R05');
        WorkflowTransition::where('action', 'custom_done')->update(['requires_checks' => true]);
        WorkflowStage::where('code', 'custom_audit')->update(['checklist' => json_encode([
            ['key' => 'cq', 'type' => 'attestation', 'label_ar' => 'أقر'],
        ])]);

        $this->assertSame([['checklist_no_way_out', 'custom_audit']], $this->errorCodes());

        $this->rule('custom_audit', 'direct_manager_review', 'custom_return', 'R05');
        $this->assertSame([], $this->errorCodes());
    }

    public function test_an_attachment_item_naming_a_document_a_bound_type_lacks_is_a_warning(): void
    {
        $this->customStep('custom_audit');
        $this->rule('direct_manager_review', 'custom_audit', 'custom_send', 'R05');
        $this->rule('custom_audit', 'receive_and_register', 'custom_done', 'R05', status: 'routed_to_hr');
        WorkflowStage::where('code', 'custom_audit')->update(['checklist' => json_encode([
            ['key' => 'cdoc', 'type' => 'attachment', 'label_ar' => 'كشف الراتب', 'document_key' => '99-nosuchdoc'],
        ])]);

        $codes = collect($this->report()['warnings'])->pluck('code');
        $this->assertContains('document_missing_for_type', $codes->all());
    }

    public function test_a_removed_rule_that_cuts_the_committee_off_is_an_error(): void
    {
        // Every stage keeps a cancel, so `stranded` alone would not see this.
        WorkflowTransition::query()
            ->where('from_stage_id', $this->draft->stage('receive_and_register')->id)
            ->where('action', 'register')
            ->delete();

        // final_approval_archiving stays reachable here: every open stage's
        // `deadline_expired` exception leads to the ministry, which approves into it.
        $this->assertContains(['unreachable', 'receive_from_committee'], $this->errorCodes());
    }

    public function test_each_rule_the_code_takes_by_name_must_be_there_once(): void
    {
        // Spec §5 E11, E8, E9: intake's system hop, the dean's skip, the ministry bypass.
        foreach (['receive_from_municipality' => 'submit', 'direct_manager_review' => 'forward', 'approval_by_authority' => 'approve'] as $stage => $action) {
            $rule = WorkflowTransition::query()->where('from_stage_id', $this->draft->stage($stage)->id)->where('action', $action);
            $rule->update(['is_exception' => true]);
            $this->assertContains(['required_rule_missing', $stage], $this->errorCodes(), $stage);
            $rule->update(['is_exception' => false]);
        }

        // The code takes `submit` and `forward` itself with no comment, so a mandatory note would 500 intake.
        foreach (['receive_from_municipality' => 'submit', 'direct_manager_review' => 'forward'] as $stage => $action) {
            $rule = WorkflowTransition::query()->where('from_stage_id', $this->draft->stage($stage)->id)->where('action', $action);
            $rule->update(['requires_comment' => true]);
            $this->assertContains(['required_rule_missing', $stage], $this->errorCodes(), $stage);
            $rule->update(['requires_comment' => false]);
        }

        $this->assertSame([], $this->errorCodes());
    }

    public function test_every_committee_decision_outcome_must_stay(): void
    {
        // The vote tally picks the action (DecisionController::ACTIONS); a deleted row makes recording impossible.
        $committee = $this->draft->stage('receive_from_committee');
        WorkflowTransition::query()->where('from_stage_id', $committee->id)->where('action', 'request_legal_opinion')->delete();

        $this->assertContains(['required_rule_missing', 'receive_from_committee'], $this->errorCodes());
    }

    public function test_without_the_manager_step_a_file_must_reach_hr_with_the_status_hr_takes(): void
    {
        $manager = $this->draft->stage('direct_manager_review');
        $submit = WorkflowTransition::query()
            ->where('from_stage_id', $this->draft->stage('receive_from_municipality')->id)
            ->where('action', 'submit');
        $submit->update(['to_stage_id' => $this->draft->stage('receive_and_register')->id]);
        WorkflowTransition::query()->where('from_stage_id', $manager->id)->delete();
        $manager->delete();

        // `submit` still stamps «قيد المراجعة»; HR's register takes only «محال إلى الموارد البشرية».
        $this->assertSame([['status_unmet', 'receive_and_register']], $this->errorCodes());

        $submit->update(['set_status_id' => RequestStatus::where('code', 'routed_to_hr')->value('id')]);
        $this->assertSame([], $this->errorCodes());
    }

    private function report(): array
    {
        return app(WorkflowVersionValidator::class)->report($this->draft->fresh());
    }

    /** @return list<array{0: string, 1: ?string}> */
    private function errorCodes(): array
    {
        return array_map(fn (array $entry) => [$entry['code'], $entry['stage_code']], $this->report()['errors']);
    }

    private function customStep(string $code): WorkflowStage
    {
        return WorkflowStage::create([
            'workflow_version_id' => $this->draft->id,
            'code' => $code,
            'order_no' => $this->draft->stages()->max('order_no') + 1,
            'name_ar' => 'مرحلة مخصصة',
        ]);
    }

    private function rule(string $from, string $to, string $action, string $roleCode = 'R05', string $status = 'in_review'): void
    {
        $stage = fn (string $code) => WorkflowStage::query()
            ->where('workflow_version_id', $this->draft->id)->where('code', $code)->value('id');

        WorkflowTransition::create([
            'from_stage_id' => $stage($from),
            'to_stage_id' => $stage($to),
            'action' => $action,
            'label_ar' => 'نتيجة',
            'required_role_id' => Role::where('code', $roleCode)->value('id'),
            'set_status_id' => RequestStatus::where('code', $status)->value('id'),
        ]);
    }
}
