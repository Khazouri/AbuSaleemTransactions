<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use App\Models\WorkflowVersion;
use App\Services\WorkflowService;
use App\Services\WorkflowStageCount;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\PassesControlGates;
use Tests\TestCase;

/**
 * Workflow designer — slice 1. Every stage belongs to a workflow version, and a
 * request runs on the version its current stage belongs to, so a version an
 * admin publishes later changes nothing for a file already in flight.
 */
class WorkflowVersioningTest extends TestCase
{
    use PassesControlGates;
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

    public function test_intake_files_onto_the_published_version_and_an_older_request_stays_on_its_own(): void
    {
        $first = Workflow::standard()->publishedVersion;
        $older = $this->requestAt($first, 'requirements_check', 'in_review');
        $second = $this->publishClone($first);

        $filer = User::factory()->create([
            'is_active' => true,
            'department_id' => Department::where('code', 'ADM')->value('id'),
        ]);
        $filer->roles()->attach(Role::where('code', 'R01')->value('id'));
        $type = RequestType::where('code', 'PROM')->firstOrFail();
        Storage::fake('local');

        $id = $this->actingAs($filer, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب على النسخة الثانية',
                'request_type_id' => $type->id,
                'decision_grade' => 9,
                'entitled_grade' => 9,
                'grade_date' => '2024-03-01',
                'attachments' => $this->mandatoryAttachments($type),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        $filed = Request::findOrFail($id);
        $this->assertSame('direct_manager_review', $filed->currentStage->code);
        $this->assertSame($second->id, $filed->currentStage->workflow_version_id);

        $moved = app(WorkflowService::class)->transition($older, 'approve', $this->userWithRole('R02'));
        $this->assertSame('receive_from_committee', $moved->currentStage->code);
        $this->assertSame($first->id, $moved->currentStage->workflow_version_id);
    }

    public function test_a_stage_code_resolves_inside_the_requests_own_version(): void
    {
        $first = Workflow::standard()->publishedVersion;
        $second = $this->publishClone($first);

        $this->assertSame(
            $first->stage('final_approval_archiving')->id,
            $this->requestAt($first, 'receive_from_committee', 'registered')->stageByCode('final_approval_archiving')->id,
        );
        $this->assertSame(
            $second->stage('final_approval_archiving')->id,
            $this->requestAt($second, 'receive_from_committee', 'registered')->stageByCode('final_approval_archiving')->id,
        );
    }

    /** Publishes a copy of $from the way slice 3's publish endpoint will. */
    private function publishClone(WorkflowVersion $from): WorkflowVersion
    {
        $draft = $from->cloneAsDraft();
        $from->update(['status' => 'retired']);
        $draft->update(['status' => 'published', 'published_at' => now()]);

        return $draft;
    }

    private function requestAt(WorkflowVersion $version, string $stageCode, string $statusCode): Request
    {
        return Request::create([
            'title' => 'ملف على نسخة محددة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => $version->stage($stageCode)->id,
            'submitted_at' => now(),
        ]);
    }

    public function test_a_request_cannot_be_reopened_at_a_stage_of_another_version(): void
    {
        $first = Workflow::standard()->publishedVersion;
        $second = $this->publishClone($first);
        $older = $this->requestAt($first, 'receive_from_committee', 'registered');

        $this->expectException(WorkflowTransitionException::class);
        $this->expectExceptionMessage('المرحلة المحددة لا تنتمي إلى مسار عمل هذا الطلب.');

        app(WorkflowService::class)->reopenAtStage($older, $second->stage('requirements_check'), $this->userWithRole('R08'), 'اختبار');
    }

    public function test_the_redo_stage_picker_lists_the_requests_own_version(): void
    {
        $first = Workflow::standard()->publishedVersion;
        $second = $this->publishClone($first);
        $older = $this->requestAt($first, 'receive_from_committee', 'registered');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();

        $forOlder = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/appeals/redo-stage-options?request_id={$older->id}")
            ->assertOk()
            ->json('data.*.id');
        $this->assertNotEmpty($forOlder);
        $this->assertSame([], array_diff($forOlder, $first->stages()->pluck('id')->all()));

        $forNew = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/appeals/redo-stage-options')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([], array_diff($forNew, $second->stages()->pluck('id')->all()));
        $this->assertSame($second->stages()->count(), app(WorkflowStageCount::class)->total());
    }

    public function test_reseeding_with_a_newer_version_published_keeps_it_published(): void
    {
        $first = Workflow::standard()->publishedVersion;
        $second = $this->publishClone($first);
        $stageCount = $second->stages()->count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame('published', $second->fresh()->status);
        $this->assertSame('retired', $first->fresh()->status);
        $this->assertSame(1, WorkflowVersion::where('status', 'published')->count());
        $this->assertSame($stageCount, $second->stages()->count());
    }

    public function test_the_redo_stage_picker_404s_for_an_unknown_request(): void
    {
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/appeals/redo-stage-options?request_id=999999')
            ->assertNotFound();
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
