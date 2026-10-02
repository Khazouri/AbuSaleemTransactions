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
use Tests\RecordsStructuredDecisions;
use Tests\RunsStudySequence;
use Tests\TestCase;

/**
 * A stand-in seat holder — someone المقرر seats without the seat's role —
 * carries that role's meeting duties while invited, and loses them once every
 * meeting they were invited to is concluded and nothing it decided is still
 * waiting on an approving body (user decision 2026-10-02).
 */
class SeatDutyGrantTest extends TestCase
{
    use RecordsStructuredDecisions;
    use RefreshDatabase;
    use RunsStudySequence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_any_user_may_be_seated_except_in_the_rapporteur_seat(): void
    {
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $rapporteur = $this->userWithRole('R02');
        $committee->members()->create(['user_id' => $rapporteur->id]);

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/committees/{$committee->id}/members", ['user_id' => $this->userWithRole('R01')->id, 'seat' => 'chair'])
            ->assertCreated();

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/committees/{$committee->id}/members", ['user_id' => $this->userWithRole('R03')->id, 'seat' => 'rapporteur'])
            ->assertStatus(422)
            // The seat is never filled by hand now (Committee::seatRapporteur()).
            ->assertJsonPath('errors.seat.0', 'مقعد المقرر يشغله مقرر النظام تلقائيًا.');
    }

    public function test_the_grant_starts_at_the_invitation_and_covers_only_meeting_duties(): void
    {
        [$standIn, $committee] = $this->standInChair();

        // Seated, not yet invited: nothing of R03's.
        $this->assertFalse($this->fresh($standIn)->hasScreenPermission('meeting_live', 'can_edit'));
        $this->assertFalse($this->fresh($standIn)->hasScreenPermission('decisions', 'can_approve'));

        $this->meetingFor($committee, $standIn);

        $user = $this->fresh($standIn);
        $this->assertTrue($user->hasScreenPermission('meeting_live', 'can_edit'));
        $this->assertTrue($user->hasScreenPermission('meeting_readiness', 'can_edit'));
        $this->assertTrue($user->hasScreenPermission('meeting_minutes', 'can_approve'));
        $this->assertTrue($user->hasScreenPermission('decisions', 'can_approve'));
        // R03 grants outside the meeting duties are never lent.
        $this->assertFalse($user->hasScreenPermission('committee_head_approval', 'can_view'));
        $this->assertFalse($user->hasScreenPermission('meeting_outputs', 'can_edit'));
    }

    public function test_the_stand_in_chair_records_their_own_items_decision_and_nothing_else(): void
    {
        [$standIn, $committee] = $this->standInChair();
        $meeting = $this->meetingFor($committee, $standIn);
        [$item, $requestRecord] = $this->itemOn($meeting);
        $outsider = $this->committeeRequest();

        $this->actingAs($standIn, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/votes", ['vote' => 'approve'])
            ->assertCreated();
        $this->actingAs($standIn, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/agenda/{$item->id}/decision", $this->decisionPayload('approve'))
            ->assertCreated();

        $this->assertSame('awaiting_municipal_approval', $requestRecord->fresh()->status->code);
        // A file on no meeting of theirs offers them none of R03's rows.
        $this->assertSame([], app(WorkflowService::class)->availableTransitions($outsider, $standIn)->all());
    }

    public function test_the_grant_outlives_the_meeting_until_its_results_are_approved(): void
    {
        [$standIn, $committee] = $this->standInChair();
        $meeting = $this->meetingFor($committee, $standIn);
        [, $requestRecord] = $this->itemOn($meeting);

        $requestRecord->update(['status_id' => $this->statusId('awaiting_municipal_approval')]);
        $meeting->update(['status' => 'completed']);
        $this->assertTrue($this->fresh($standIn)->hasScreenPermission('decisions', 'can_approve'));

        $requestRecord->update(['status_id' => $this->statusId('in_execution')]);
        $this->assertFalse($this->fresh($standIn)->hasScreenPermission('decisions', 'can_approve'));
    }

    public function test_a_cancelled_meeting_or_one_that_sent_nothing_for_approval_ends_the_grant(): void
    {
        [$standIn, $committee] = $this->standInChair();
        $meeting = $this->meetingFor($committee, $standIn);
        [, $requestRecord] = $this->itemOn($meeting);

        $meeting->update(['status' => 'cancelled']);
        $this->assertFalse($this->fresh($standIn)->hasScreenPermission('meeting_live', 'can_edit'));

        $requestRecord->update(['status_id' => $this->statusId('not_approved')]);
        $meeting->update(['status' => 'completed']);
        $this->assertFalse($this->fresh($standIn)->hasScreenPermission('meeting_live', 'can_edit'));
    }

    public function test_a_holder_of_the_role_is_unaffected_when_the_grant_ends(): void
    {
        $chair = $this->userWithRole('R03');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $committee->members()->create(['user_id' => $chair->id, 'seat' => 'chair', 'is_head' => true]);
        $this->meetingFor($committee, $chair)->update(['status' => 'cancelled']);

        $this->assertTrue($this->fresh($chair)->hasScreenPermission('decisions', 'can_approve'));
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
        $meeting->attendees()->create(['user_id' => $seatHolder->id, 'attended' => true]);

        return $meeting;
    }

    /** @return array{0: MeetingRequest, 1: Request} */
    private function itemOn(Meeting $meeting): array
    {
        $requestRecord = $this->committeeRequest();
        $item = $meeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]);

        return [$this->completeStudySequence($item), $requestRecord];
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
