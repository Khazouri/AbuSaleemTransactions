<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A stand-in seat holder — someone still seated after the seat's role passed
 * to someone else (Stage 103: the seat follows at the next scheduling) —
 * carries that role's meeting duties while invited, and loses them once every
 * meeting they were invited to is concluded and nothing it decided is still
 * waiting on an approving body (user decision 2026-10-02).
 */
class SeatDutyGrantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_grant_starts_at_the_invitation_and_covers_only_meeting_duties(): void
    {
        [$standIn, $committee] = $this->standInChair();

        // Seated, not yet invited: nothing of R03's.
        $this->assertFalse($this->fresh($standIn)->hasScreenPermission('meeting_agenda', 'can_approve'));
        $this->assertFalse($this->fresh($standIn)->hasScreenPermission('meetings_dashboard', 'can_edit'));

        $this->meetingFor($committee, $standIn);

        $user = $this->fresh($standIn);
        $this->assertTrue($user->hasScreenPermission('meeting_agenda', 'can_approve'));
        $this->assertTrue($user->hasScreenPermission('meetings_dashboard', 'can_edit'));
        $this->assertTrue($user->hasScreenPermission('meetings', 'can_edit'));
        // R03 grants outside the meeting duties are never lent.
        $this->assertFalse($user->hasScreenPermission('committee_head_approval', 'can_view'));
        $this->assertFalse($user->hasScreenPermission('meeting_outputs', 'can_edit'));
    }

    /**
     * The committee's outcome rows are R02's since 2026-10-18, so what a
     * stand-in chair still borrows at the committee stage is R03's own pair:
     * return_to_study and cancel — on their meeting's file only.
     */
    public function test_the_stand_in_chair_gets_r03s_rows_on_their_own_meetings_file_and_nothing_else(): void
    {
        [$standIn, $committee] = $this->standInChair();
        $meeting = $this->meetingFor($committee, $standIn);
        [, $requestRecord] = $this->itemOn($meeting);
        $outsider = $this->committeeRequest();

        $workflow = app(WorkflowService::class);
        $offered = $workflow->availableTransitions($requestRecord, $standIn)->pluck('action')->all();
        $this->assertContains('return_to_study', $offered);
        $this->assertNotContains('approve', $offered);
        $this->assertSame([], $workflow->availableTransitions($outsider, $standIn)->all());
    }

    public function test_the_grant_outlives_the_meeting_until_its_results_are_approved(): void
    {
        [$standIn, $committee] = $this->standInChair();
        $meeting = $this->meetingFor($committee, $standIn);
        [, $requestRecord] = $this->itemOn($meeting);

        $requestRecord->update(['status_id' => $this->statusId('awaiting_municipal_approval')]);
        $meeting->update(['status' => 'completed']);
        $this->assertTrue($this->fresh($standIn)->hasScreenPermission('meeting_agenda', 'can_approve'));

        $requestRecord->update(['status_id' => $this->statusId('in_execution')]);
        $this->assertFalse($this->fresh($standIn)->hasScreenPermission('meeting_agenda', 'can_approve'));
    }

    public function test_a_cancelled_meeting_or_one_that_sent_nothing_for_approval_ends_the_grant(): void
    {
        [$standIn, $committee] = $this->standInChair();
        $meeting = $this->meetingFor($committee, $standIn);
        [, $requestRecord] = $this->itemOn($meeting);

        $meeting->update(['status' => 'cancelled']);
        $this->assertFalse($this->fresh($standIn)->hasScreenPermission('meeting_agenda', 'can_approve'));

        $requestRecord->update(['status_id' => $this->statusId('not_approved')]);
        $meeting->update(['status' => 'completed']);
        $this->assertFalse($this->fresh($standIn)->hasScreenPermission('meeting_agenda', 'can_approve'));
    }

    public function test_a_holder_of_the_role_is_unaffected_when_the_grant_ends(): void
    {
        $chair = $this->userWithRole('R03');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $committee->members()->create(['user_id' => $chair->id, 'seat' => 'chair', 'is_head' => true]);
        $this->meetingFor($committee, $chair)->update(['status' => 'cancelled']);

        $this->assertTrue($this->fresh($chair)->hasScreenPermission('meeting_agenda', 'can_approve'));
        $this->assertTrue($this->fresh($chair)->hasScreenPermission('committee_head_approval', 'can_view'));
    }

    /** @return array{0: User, 1: Committee} */
    private function standInChair(): array
    {
        $standIn = $this->userWithRole('R01');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        CommitteeMember::create(['committee_id' => $committee->id, 'user_id' => $standIn->id, 'seat' => 'chair', 'is_head' => true]);

        return [$standIn, $committee];
    }

    /** The invitation is the attendee row MeetingController::store() writes for each seat. */
    private function meetingFor(Committee $committee, User $seatHolder): Meeting
    {
        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'الاجتماع الشهري',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $seatHolder->id,
            'chairman_user_id' => $seatHolder->id,
        ]);
        $meeting->attendees()->create(['user_id' => $seatHolder->id]);

        return $meeting;
    }

    /** @return array{0: MeetingRequest, 1: Request} */
    private function itemOn(Meeting $meeting): array
    {
        $requestRecord = $this->committeeRequest();
        $item = $meeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]);

        return [$item, $requestRecord];
    }

    private function committeeRequest(): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب ترقية',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => $this->statusId('in_meeting'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $this->userWithRole('R01')->id,
            'submitted_at' => now(),
        ]);
    }

    private function statusId(string $code): int
    {
        return RequestStatus::where('code', $code)->value('id');
    }

    /** A fresh instance, since screenPermissions() is memoised per model. */
    private function fresh(User $user): User
    {
        return User::findOrFail($user->id);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
