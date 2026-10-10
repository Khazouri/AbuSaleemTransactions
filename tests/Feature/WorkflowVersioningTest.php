<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
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
}
