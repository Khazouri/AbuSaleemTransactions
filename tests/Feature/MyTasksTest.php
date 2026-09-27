<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\Committee;
use App\Models\Decision;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\SitsOnCommittee;
use Tests\TestCase;

/**
 * The unified pending-task inbox that replaced the five per-role queues.
 *
 * The two properties that matter most, checked throughout: a source appears
 * only for someone who can act on it, and every row links to a screen that
 * same person can actually open (assertLinksAreOpenable). Those are the properties that make an inbox worth having
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
        // Decision wizard — a request-page task opens the wizard over the file.
        $this->assertSame(['decide' => 1], $sources['completion']['tasks'][0]['route']['query']);
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
        $this->assertSame(['decide' => 1], $tasks->firstWhere('title', $theirs->title)['route']['query']);
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

        $inbox = $this->inbox($this->userWithRole('R11'));
        $this->assertArrayHasKey('legal_review', $inbox);
        // Decision wizard, sub-project 2 — the opinion is given from the file's wizard.
        $this->assertSame('request_details', $inbox['legal_review']['tasks'][0]['route']['name']);
        $this->assertSame(['decide' => 1], $inbox['legal_review']['tasks'][0]['route']['query']);
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

        $this->assertLinksAreOpenable($reviewer, $sources);
    }

    /**
     * The example the user named: the employee's own manager approves the
     * request in one click, so it must be waiting in that manager's inbox —
     * and nobody else's while that manager is live.
     */
    public function test_the_subjects_manager_is_offered_the_forward_step_and_nobody_else(): void
    {
        $manager = $this->userWithRole('R02');
        $otherManager = $this->userWithRole('R02');
        $admin = $this->userWithRole('R08');
        $employee = $this->userWithRole('R01');
        $employee->forceFill(['manager_id' => $manager->id])->save();
        $file = $this->requestAt('direct_manager_review', 'in_review', $employee);

        $sources = $this->inbox($manager);
        $task = collect($sources['workflow_step']['tasks'])->firstWhere('title', $file->title);
        $this->assertNotNull($task);
        $this->assertSame('forward', $task['action']);
        $this->assertSame('request_details', $task['route']['name']);
        $this->assertSame(['decide' => 1], $task['route']['query']);
        $this->assertLinksAreOpenable($manager, $sources);

        $this->assertArrayNotHasKey('workflow_step', $this->inbox($otherManager));
        $this->assertArrayNotHasKey('workflow_step', $this->inbox($employee));
        $this->assertArrayNotHasKey('workflow_step', $this->inbox($admin), 'a live manager owns the step alone');

        // With no live manager the file would stall, so R08 is offered it.
        $employee->forceFill(['manager_id' => null])->save();
        $this->assertSame('forward', $this->inbox($admin)['workflow_step']['tasks'][0]['action']);
    }

    public function test_hr_is_offered_the_registration_step(): void
    {
        $hr = $this->userWithRole('R12');
        $file = $this->requestAt('receive_and_register', 'routed_to_hr', $this->userWithRole('R01'));

        $task = $this->inbox($hr)['workflow_step']['tasks'][0];
        $this->assertSame($file->title, $task['title']);
        $this->assertSame('register', $task['action']);
        $this->assertArrayNotHasKey('workflow_step', $this->inbox($this->userWithRole('R01')));
    }

    /** The ministry's own escalation loops onto itself, so it must not be a task forever. */
    public function test_the_admin_is_offered_overdue_files_except_the_ministry_self_loop(): void
    {
        $admin = $this->userWithRole('R08');
        $late = $this->requestAt('requirements_check', 'in_review', $this->userWithRole('R01'));
        $late->forceFill(['overdue_at' => now()->subDay()])->save();
        $looping = $this->requestAt('local_governance_ministry', 'awaiting_central_approval', $this->userWithRole('R01'));
        $looping->forceFill(['overdue_at' => now()->subDay()])->save();

        $titles = collect($this->inbox($admin)['overdue']['tasks'])->pluck('title');
        $this->assertContains($late->title, $titles);
        $this->assertNotContains($looping->title, $titles);
        $this->assertArrayNotHasKey('overdue', $this->inbox($this->userWithRole('R02')));
    }

    public function test_the_chair_is_offered_adopting_the_agenda_and_convening_but_a_non_member_is_not(): void
    {
        [$meeting, $seats] = $this->meetingWithSeats(['status' => 'scheduled', 'scheduled_at' => now()]);
        MeetingRequest::create(['meeting_id' => $meeting->id, 'agenda_order' => 1, 'item_type' => 'employee_request']);

        $sources = $this->inbox($seats['chair']);
        $actions = collect($sources['meeting_duty']['tasks'])->pluck('action');
        $this->assertContains('adopt_agenda', $actions);
        $this->assertContains('convene', $actions);
        $this->assertLinksAreOpenable($seats['chair'], $sources);

        $this->assertArrayNotHasKey('meeting_duty', $this->inbox($this->userWithRole('R03')));

        // A sitting weeks away is not today's work.
        $meeting->update(['scheduled_at' => now()->addWeeks(3)]);
        $this->assertNotContains('convene', collect($this->inbox($seats['chair'])['meeting_duty']['tasks'])->pluck('action'));
    }

    public function test_the_chair_is_offered_recording_a_voted_decision_and_the_rapporteur_is_not(): void
    {
        [$meeting, $seats] = $this->meetingWithSeats([
            'status' => 'scheduled', 'scheduled_at' => now(), 'convened_at' => now(), 'agenda_adopted_at' => now(),
        ]);
        $file = $this->requestAt('receive_from_committee', 'ready', $this->userWithRole('R01'));
        $item = MeetingRequest::create([
            'meeting_id' => $meeting->id, 'request_id' => $file->id, 'agenda_order' => 1, 'item_type' => 'employee_request',
        ]);
        $item->forceFill(['study_sequence_completed_at' => now()])->save();
        DB::table('votes')->insert([
            'meeting_request_id' => $item->id, 'user_id' => $seats['legal']->id, 'vote' => 'approve', 'voted_at' => now(),
        ]);

        $task = collect($this->inbox($seats['chair'])['meeting_duty']['tasks'])->firstWhere('action', 'record_decision');
        $this->assertNotNull($task);
        $this->assertSame(['meeting' => $meeting->id, 'item' => $item->id, 'decide' => 1], $task['route']['query']);

        $rapporteurActions = collect($this->inbox($seats['rapporteur'])['meeting_duty']['tasks'] ?? [])->pluck('action');
        $this->assertNotContains('record_decision', $rapporteurActions);
    }

    /** Generate → review → close the sitting, each offered only while it is the next step. */
    public function test_the_minutes_duties_follow_one_another(): void
    {
        [$meeting, $seats] = $this->meetingWithSeats([
            'status' => 'scheduled', 'scheduled_at' => now()->subHour(), 'convened_at' => now()->subHour(), 'agenda_adopted_at' => now(),
        ]);
        MeetingRequest::create(['meeting_id' => $meeting->id, 'agenda_order' => 1, 'item_type' => 'employee_request', 'item_state' => 'complete']);
        $actions = fn (User $user) => collect($this->inbox($user)['meeting_duty']['tasks'] ?? [])->pluck('action')->all();

        $this->assertContains('generate_minutes', $actions($seats['rapporteur']));
        $this->assertNotContains('review_minutes', $actions($seats['chair']));

        $minutesId = DB::table('meeting_minutes')->insertGetId(['meeting_id' => $meeting->id, 'status' => 'draft']);
        $this->assertNotContains('generate_minutes', $actions($seats['rapporteur']));
        $this->assertContains('review_minutes', $actions($seats['chair']));
        $this->assertNotContains('review_minutes', $actions($seats['rapporteur']), 'R02 holds no review grant');

        // Sent back: regenerating is the rapporteur's move again.
        DB::table('meeting_minutes')->where('id', $minutesId)->update(['review_comment' => 'صحح البند الأول']);
        $this->assertContains('generate_minutes', $actions($seats['rapporteur']));
        $this->assertNotContains('review_minutes', $actions($seats['chair']));

        DB::table('meeting_minutes')->where('id', $minutesId)->update(['status' => 'approved', 'review_comment' => null]);
        $this->assertContains('close_meeting', $actions($seats['rapporteur']));
    }

    public function test_the_execution_soundness_certification_goes_to_the_rapporteur_and_execution_to_hr(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $hr = $this->userWithRole('R12');
        $this->requestAt('final_approval_archiving', 'final_approved', $this->userWithRole('R01'));
        $executing = $this->decidedRequestAt('final_approval_archiving', 'in_execution');

        $this->assertContains('execution_soundness', $this->postDecisionActions($rapporteur));
        $this->assertNotContains('execution_soundness', $this->postDecisionActions($hr), 'HR holds no certification grant');

        $sources = $this->inbox($hr);
        $task = collect($sources['post_decision']['tasks'])->firstWhere('action', 'execute');
        $this->assertSame('meeting_outputs', $task['route']['name']);
        $this->assertSame(['meeting' => $executing->meetingRequests()->value('meeting_id')], $task['route']['query']);
        $this->assertLinksAreOpenable($hr, $sources);
    }

    /** Archive first (each file by its owner), then close — never both at once. */
    public function test_archiving_hands_over_to_closure(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $hr = $this->userWithRole('R12');
        $file = $this->decidedRequestAt('final_approval_archiving', 'executed');

        $this->assertContains('archive_committee_file', $this->postDecisionActions($rapporteur));
        $this->assertContains('archive_service_file', $this->postDecisionActions($hr));
        $this->assertNotContains('close', $this->postDecisionActions($hr));

        $file->forceFill(['committee_file_archived_at' => now()])->save();
        $this->assertNotContains('close', $this->postDecisionActions($hr), 'the service file is still owed');

        $file->forceFill(['service_file_archived_at' => now()])->save();
        $this->assertContains('close', $this->postDecisionActions($hr));
        $this->assertNotContains('archive_committee_file', $this->postDecisionActions($rapporteur));
        $this->assertNotContains('archive_service_file', $this->postDecisionActions($hr));
    }

    public function test_an_approval_return_referral_and_suspension_await_the_rapporteur(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $returned = $this->requestAt('approval_by_authority', 'returned_by_approving_body', $this->userWithRole('R01'));
        DB::table('approval_returns')->insert([
            'request_id' => $returned->id, 'return_kind' => 'formal', 'return_reason_code' => 'other',
            'return_note' => 'نقص', 'received_at' => now(),
        ]);
        DB::table('approval_referrals')->insert([
            'request_id' => $returned->id, 'letter_number' => 'L-1', 'referred_to_body' => 'الوزارة', 'referred_at' => now(),
        ]);
        $suspended = $this->requestAt('final_approval_archiving', 'execution_suspended', $this->userWithRole('R01'));
        DB::table('request_suspensions')->insert([
            'request_id' => $suspended->id, 'ground' => 'other', 'detail' => 'واقعة', 'suspended_at' => now()->subDay(),
        ]);

        $actions = $this->postDecisionActions($rapporteur);
        $this->assertContains('resolve_approval_return', $actions);
        $this->assertContains('record_referral_result', $actions);
        $this->assertNotContains('lift_suspension', $actions, 'the legal opinion comes first');

        DB::table('request_legal_reviews')->insert([
            'request_id' => $suspended->id, 'verdict' => 'sound', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertContains('lift_suspension', $this->postDecisionActions($rapporteur));
    }

    public function test_open_lifecycle_records_await_the_rapporteur_but_never_their_own_correction(): void
    {
        $recorder = $this->userWithRole('R02');
        $colleague = $this->userWithRole('R02');
        $file = $this->requestAt('receive_from_committee', 'ready', $this->userWithRole('R01'));
        DB::table('request_corrections')->insert([
            'request_id' => $file->id, 'error_kind' => 'clerical', 'detail' => 'خطأ', 'incorrect_value' => 'أ',
            'corrected_value' => 'ب', 'recorded_by_user_id' => $recorder->id,
        ]);
        DB::table('request_document_conflicts')->insert(['request_id' => $file->id, 'conflict_kind' => 'other', 'detail' => 'تعارض']);
        DB::table('request_special_cases')->insert(['request_id' => $file->id, 'case_kind' => 'document_lost', 'determinations' => '{}']);
        DB::table('request_withdrawals')->insert(['request_id' => $file->id, 'reason' => 'رغبة', 'requested_at' => now()]);

        $colleagueActions = collect($this->inbox($colleague)['open_record']['tasks'])->pluck('action')->all();
        foreach (['approve_correction', 'resolve_document_conflict', 'resolve_special_case', 'determine_withdrawal'] as $action) {
            $this->assertContains($action, $colleagueActions);
        }
        $this->assertNotContains('approve_correction', collect($this->inbox($recorder)['open_record']['tasks'])->pluck('action'));
    }

    public function test_each_appeal_step_awaits_the_verifier_but_never_the_appellant(): void
    {
        $verifier = $this->userWithRole('R02');
        $appellant = $this->userWithRole('R01');
        $submitted = $this->appealIn('submitted', $appellant);
        $this->appealIn('formal_verification', $appellant);
        $this->appealIn('file_assembly', $appellant);
        $this->appealIn('rejected', $appellant);
        $this->appealIn('submitted', $verifier);

        $sources = $this->inbox($verifier);
        $tasks = collect($sources['appeal']['tasks']);
        $this->assertSame(['close', 'jurisdiction_test', 'legal_review', 'verify'], $tasks->pluck('action')->sort()->values()->all());
        $this->assertSame(['status' => 'submitted'], $tasks->firstWhere('action', 'verify')['route']['query']);
        $this->assertSame($submitted->originalRequest->title, $tasks->firstWhere('action', 'verify')['title']);
        $this->assertLinksAreOpenable($verifier, $sources);

        $this->assertArrayNotHasKey('appeal', $this->inbox($appellant));
    }

    public function test_an_appeal_ready_for_the_committee_awaits_nomination_by_a_seated_rapporteur(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $this->appealIn('legal_review', $this->userWithRole('R01'));

        $this->assertArrayNotHasKey('appeal', $this->inbox($rapporteur), 'an unseated member cannot open the agenda');

        $this->seatOn(Committee::create(['name_ar' => 'لجنة شؤون الموظفين']), $rapporteur);
        $sources = $this->inbox($rapporteur);
        $task = $sources['appeal']['tasks'][0];
        $this->assertSame('nominate', $task['action']);
        $this->assertSame('meeting_agenda', $task['route']['name']);
        $this->assertLinksAreOpenable($rapporteur, $sources);
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
        // fresh(): screenPermissions() is memoized per instance, and several
        // tests change a seat or a manager between two calls.
        $sources = $this->actingAs($actor->fresh(), 'sanctum')
            ->getJson('/api/my-tasks')
            ->assertOk()
            ->json('data.sources');

        return collect($sources)->keyBy('code')->all();
    }

    /**
     * Every task must open a screen the same actor may view. Route names are
     * screen codes except `meeting_details`, whose screen is `meetings`.
     *
     * @param  array<string, array<string, mixed>>  $sources
     */
    private function assertLinksAreOpenable(User $actor, array $sources): void
    {
        foreach ($sources as $source) {
            foreach ($source['tasks'] as $task) {
                $screen = ['meeting_details' => 'meetings'][$task['route']['name']] ?? $task['route']['name'];
                $this->assertTrue(
                    $actor->fresh()->hasScreenPermission($screen, 'can_view'),
                    "{$task['source']} links to {$task['route']['name']}, which this actor cannot open",
                );
            }
        }
    }

    /** @return list<string> */
    private function postDecisionActions(User $actor): array
    {
        return collect($this->inbox($actor)['post_decision']['tasks'] ?? [])->pluck('action')->all();
    }

    /** @return array{0: Meeting, 1: array<string, User>} */
    private function meetingWithSeats(array $attributes): array
    {
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $seats = $this->fillFiveSeats($committee);
        $meeting = new Meeting(['committee_id' => $committee->id, 'title' => 'اجتماع '.fake()->unique()->numerify('###')]);
        $meeting->forceFill($attributes)->save();

        return [$meeting, $seats];
    }

    private function decidedRequestAt(string $stageCode, string $statusCode): Request
    {
        $file = $this->requestAt($stageCode, $statusCode, $this->userWithRole('R01'));
        $meeting = Meeting::create([
            'committee_id' => Committee::create(['name_ar' => 'لجنة'])->id,
            'title' => 'اجتماع',
            'status' => 'completed',
            'scheduled_at' => now()->subWeek(),
        ]);
        $item = MeetingRequest::create(['meeting_id' => $meeting->id, 'request_id' => $file->id, 'agenda_order' => 1]);
        Decision::create(['meeting_request_id' => $item->id, 'outcome' => 'approve', 'decided_at' => now()->subWeek()]);

        return $file;
    }

    private function appealIn(string $statusCode, User $appellant): Appeal
    {
        return Appeal::create([
            'appellant_user_id' => $appellant->id,
            'original_request_id' => $this->requestAt('final_approval_archiving', 'final_approved', $appellant)->id,
            'original_decision_reference' => 'قرار',
            'known_at' => now()->subDay(),
            'appeal_reasons' => 'أسباب',
            'final_request' => 'طلب',
            'appeal_status_id' => AppealStatus::where('code', $statusCode)->value('id'),
        ]);
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
