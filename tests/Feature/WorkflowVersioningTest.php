<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use App\Models\WorkflowVersion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Workflow designer — slice 1. Every stage belongs to a workflow version, and a
 * request runs on the version its current stage belongs to, so a version an
 * admin publishes later changes nothing for a file already in flight.
 */
class WorkflowVersioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_every_seeded_stage_and_rule_belongs_to_the_published_standard_version(): void
    {
        $version = Workflow::standard()->publishedVersion;

        $this->assertSame(1, $version->number);
        $this->assertSame(WorkflowStage::count(), $version->stages()->count());
        $this->assertSame(WorkflowTransition::count(), $version->transitions()->count());
        $this->assertSame('direct_manager_review', $version->stage('direct_manager_review')->code);
    }

    public function test_a_clone_copies_every_stage_and_rule_onto_rows_of_its_own(): void
    {
        $published = Workflow::standard()->publishedVersion;
        $draft = $published->cloneAsDraft();

        $this->assertSame('draft', $draft->status);
        $this->assertSame(2, $draft->number);
        $this->assertSame($published->id, $draft->cloned_from_version_id);
        $this->assertEqualsCanonicalizing(
            $published->stages()->pluck('code')->all(),
            $draft->stages()->pluck('code')->all(),
        );
        $this->assertSame($published->transitions()->count(), $draft->transitions()->count());
        // No copied rule may land on a stage of another version.
        $this->assertSame(0, $draft->transitions()->whereNotIn('to_stage_id', $draft->stages()->pluck('id'))->count());
    }

    public function test_reseeding_updates_standard_v1_and_leaves_other_versions_untouched(): void
    {
        $draft = Workflow::standard()->publishedVersion->cloneAsDraft();
        $draft->stages()->where('code', 'requirements_check')->update(['name_ar' => 'اسم عدّله المشرف']);
        $stageCount = $draft->stages()->count();
        $ruleCount = $draft->transitions()->count();

        $this->seed(DatabaseSeeder::class);

        $draft = WorkflowVersion::findOrFail($draft->id);
        $this->assertSame('draft', $draft->status);
        $this->assertSame('اسم عدّله المشرف', $draft->stage('requirements_check')->name_ar);
        $this->assertSame($stageCount, $draft->stages()->count());
        $this->assertSame($ruleCount, $draft->transitions()->count());
        $this->assertSame(1, WorkflowVersion::where('status', 'published')->count());
        $this->assertSame(1, Workflow::standard()->publishedVersion->number);
    }
}
