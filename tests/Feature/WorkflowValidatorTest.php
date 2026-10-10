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
 * administrative_routing) are history, not mistakes, and stay unreported.
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
        $this->rule('custom_audit', 'receive_and_register', 'custom_done', 'R11');

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

    private function rule(string $from, string $to, string $action, string $roleCode = 'R05'): void
    {
        $stage = fn (string $code) => WorkflowStage::query()
            ->where('workflow_version_id', $this->draft->id)->where('code', $code)->value('id');

        WorkflowTransition::create([
            'from_stage_id' => $stage($from),
            'to_stage_id' => $stage($to),
            'action' => $action,
            'label_ar' => 'نتيجة',
            'required_role_id' => Role::where('code', $roleCode)->value('id'),
            'set_status_id' => RequestStatus::where('code', 'in_review')->value('id'),
        ]);
    }
}
