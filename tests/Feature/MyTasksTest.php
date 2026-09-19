<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SitsOnCommittee;
use Tests\TestCase;

/**
 * The unified pending-task inbox that replaced the five per-role queues.
 *
 * The two tests that matter most are the last two: a source appears only for
 * someone who can act on it, and every row links to a screen that same person
 * can actually open. Those are the properties that make an inbox worth having
 * rather than a list of things that turn out to be somebody else's.
 */
class MyTasksTest extends TestCase
{
    use RefreshDatabase;
    use SitsOnCommittee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_an_employee_sees_their_own_file_that_came_back_for_completion(): void
    {
        $employee = $this->userWithRole('R01');
        $mine = $this->requestAt('receive_from_municipality', 'completion_required', $employee);
        $this->requestAt('receive_from_municipality', 'completion_required', $this->userWithRole('R01'));

        $sources = $this->inbox($employee);

        $this->assertSame(['completion'], array_keys($sources));
        $this->assertSame(1, $sources['completion']['count']);
        $this->assertSame($mine->title, $sources['completion']['tasks'][0]['title']);
        $this->assertSame('request_details', $sources['completion']['tasks'][0]['route']['name']);
    }

    /**
     * The approval source is the five retired queues, folded into one: a
     * reviewer sees their checkpoint without visiting a screen of its own.
     */
    public function test_a_reviewer_sees_work_at_their_own_checkpoint_but_never_their_own_file(): void
    {
        $reviewer = $this->userWithRole('R02');
        $theirs = $this->requestAt('requirements_check', 'in_review', $this->userWithRole('R01'));
        $own = $this->requestAt('requirements_check', 'in_review', $reviewer);

        $tasks = collect($this->inbox($reviewer)['approval']['tasks']);

        $this->assertTrue($tasks->contains('title', $theirs->title));
        $this->assertFalse($tasks->contains('title', $own->title), 'a reviewer must not approve their own file');
    }

    /** A checkpoint another role owns is not this one's work. */
    public function test_a_reviewer_does_not_see_another_checkpoints_queue(): void
    {
        $reviewer = $this->userWithRole('R02');
        $ministry = $this->requestAt('local_governance_ministry', 'awaiting_central_approval', $this->userWithRole('R01'));

        $sources = $this->inbox($reviewer);
        $tasks = collect($sources['approval']['tasks'] ?? []);

        $this->assertFalse($tasks->contains('title', $ministry->title));
    }

    public function test_the_legal_member_sees_the_review_queue_and_an_employee_does_not(): void
    {
        $this->requestAt('receive_from_committee', 'under_legal_review', $this->userWithRole('R01'));

        $this->assertArrayHasKey('legal_review', $this->inbox($this->userWithRole('R11')));
        $this->assertArrayNotHasKey('legal_review', $this->inbox($this->userWithRole('R01')));
    }

    /**
     * The rule that makes the inbox per-user: a source is collected only for
     * someone holding the grant that lets them act on it. An employee holds
     * none of the committee-side grants, so none of those sources reach them
     * even though the underlying rows exist.
     */
    public function test_an_employee_is_offered_none_of_the_committee_side_sources(): void
    {
        $this->requestAt('requirements_check', 'in_review', $this->userWithRole('R01'));
        $this->requestAt('receive_from_committee', 'ready', $this->userWithRole('R01'));
        $this->requestAt('receive_from_committee', 'under_legal_review', $this->userWithRole('R01'));

        $sources = array_keys($this->inbox($this->userWithRole('R01')));

        foreach (['approval', 'candidate', 'legal_review', 'vote', 'minutes_signature'] as $code) {
            $this->assertNotContains($code, $sources);
        }
    }

    /**
     * The invariant that would have caught the worst thing this change could
     * have done: a task whose destination the router then bounces the reader
     * out of. Every route name the collector emits is also a screen code, so
     * the check is direct.
     */
    public function test_every_task_links_to_a_screen_the_same_actor_may_open(): void
    {
        $reviewer = $this->userWithRole('R02');
        $this->seatOn(Committee::create(['name_ar' => 'لجنة شؤون الموظفين']), $reviewer);

        $this->requestAt('requirements_check', 'in_review', $this->userWithRole('R01'));
        $this->requestAt('receive_from_committee', 'ready', $this->userWithRole('R01'));
        $this->requestAt('receive_from_municipality', 'completion_required', $reviewer);

        $sources = $this->inbox($reviewer);
        $this->assertNotEmpty($sources, 'fixture produced no tasks, so this proves nothing');

        foreach ($sources as $source) {
            foreach ($source['tasks'] as $task) {
                $this->assertTrue(
                    $reviewer->hasScreenPermission($task['route']['name'], 'can_view'),
                    "{$task['source']} links to {$task['route']['name']}, which this actor cannot open",
                );
            }
        }
    }

    public function test_the_total_counts_every_source(): void
    {
        $reviewer = $this->userWithRole('R02');
        $this->requestAt('requirements_check', 'in_review', $this->userWithRole('R01'));
        $this->requestAt('receive_from_municipality', 'completion_required', $reviewer);

        $data = $this->actingAs($reviewer, 'sanctum')
            ->getJson('/api/my-tasks')
            ->assertOk()
            ->json('data');

        $this->assertSame(
            array_sum(array_column($data['sources'], 'count')),
            $data['total'],
        );
        $this->assertFalse($data['truncated']);
    }

    /** @return array<string, array<string, mixed>> keyed by source code */
    private function inbox(User $actor): array
    {
        $sources = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/my-tasks')
            ->assertOk()
            ->json('data.sources');

        return collect($sources)->keyBy('code')->all();
    }

    private function requestAt(string $stageCode, string $statusCode, User $creator): Request
    {
        return Request::create([
            'reference_number' => 'PM-COM/2026/'.fake()->unique()->numerify('####'),
            'title' => 'طلب '.fake()->unique()->numerify('###'),
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now()->subDays(3),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
