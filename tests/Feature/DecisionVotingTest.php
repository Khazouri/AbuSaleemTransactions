<?php

namespace Tests\Feature;

use App\Models\Committee;
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
use Tests\RecordsStructuredDecisions;
use Tests\RunsStudySequence;
use Tests\TestCase;

/** Stage 21 — committee voting and decision recording drives WorkflowService directly. */
class DecisionVotingTest extends TestCase
{
    use RecordsStructuredDecisions;
    use RefreshDatabase;
    use RunsStudySequence;

    public function test_a_majority_approve_vote_and_recorded_decision_advances_the_request(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated()
            ->assertJsonPath('data.vote', 'approve');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('approve'))
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'approve')
            ->assertJsonPath('data.votes_approve_count', 2);

        $requestRecord = $agendaItem->request()->first()->fresh();
        $stageEight = WorkflowStage::where('code', 'approval_by_authority')->firstOrFail();

        $this->assertSame($stageEight->id, $requestRecord->current_stage_id);
        // Stage 69 — Art. 38 code 15 (بانتظار اعتماد البلدية): the decision
        // and the referral to the البلدية are one act here, so the arrival
        // status is the more specific of Art. 38's 12/15 pair.
        $this->assertSame('awaiting_municipal_approval', $requestRecord->status->code);
        $this->assertDatabaseHas('approvals', [
            'request_id' => $requestRecord->id,
            'level' => 2,
            'action' => 'approve',
        ]);
        $this->assertDatabaseHas('decisions', [
            'meeting_request_id' => $agendaItem->id,
            'outcome' => 'approve',
            'decided_by_user_id' => $head->id,
        ]);

        // Voting is closed once a decision exists for the agenda item.
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'reject'])
            ->assertStatus(422);
    }

    public function test_an_abstain_vote_is_tallied_but_never_leads_the_plurality(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, $committee, $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $third = $this->userWithRole('R04');
        $committee->members()->create(['user_id' => $third->id]);
        $meeting->attendees()->create(['user_id' => $third->id, 'attended' => true]);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();
        $this->actingAs($third, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'abstain'])
            ->assertCreated()
            ->assertJsonPath('data.vote', 'abstain');

        $this->actingAs($head, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('approve'))
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'approve')
            ->assertJsonPath('data.votes_approve_count', 2)
            ->assertJsonPath('data.votes_abstain_count', 1);

        $this->assertDatabaseHas('decisions', [
            'meeting_request_id' => $agendaItem->id,
            'outcome' => 'approve',
            'votes_abstain_count' => 1,
        ]);
    }

    public function test_a_majority_defer_vote_keeps_the_request_at_the_committee_stage(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'defer'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'defer'])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('defer', [
                'comment' => 'الملف غير مكتمل، يؤجل للاجتماع القادم',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'defer');

        $stageSeven = WorkflowStage::where('code', 'receive_from_committee')->firstOrFail();
        $requestRecord = $agendaItem->request()->first()->fresh();

        $this->assertSame($stageSeven->id, $requestRecord->current_stage_id);
        $this->assertSame('deferred', $requestRecord->status->code);
    }

    public function test_committee_head_cannot_record_an_approval_for_their_own_request(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();
        $agendaItem->request()->update(['created_by_user_id' => $head->id]);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('approve'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يجوز للمستخدم اعتماد طلبه الخاص.');

        $requestRecord = $agendaItem->request()->firstOrFail()->fresh();
        $this->assertSame(WorkflowStage::where('code', 'receive_from_committee')->value('id'), $requestRecord->current_stage_id);
        $this->assertDatabaseMissing('decisions', ['meeting_request_id' => $agendaItem->id]);
    }

    public function test_a_tied_vote_cannot_be_recorded_automatically(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'reject'])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('approve', ['comment' => 'تعادل']))
            ->assertStatus(422);

        $this->assertDatabaseMissing('decisions', ['meeting_request_id' => $agendaItem->id]);
    }

    public function test_voting_is_restricted_to_committee_members_who_attended(): void
    {
        $this->seed(DatabaseSeeder::class);

        [, , $committee, $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->roles()->attach(Role::where('code', 'R04')->value('id'));

        // Membership gate — an outsider gets 404, not the 422 they used to:
        // they cannot see this sitting at all, and a 422 explaining why they
        // may not vote on it would confirm it exists. The reasoned refusal is
        // reserved for someone who CAN see the meeting, as below.
        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertStatus(404);

        // A real member who has not been marked as attended cannot vote either
        // — and still gets DecisionEligibility's explained 422, because a seat
        // is what earns you a reason rather than a blank wall.
        $absentMember = $this->userWithRole('R04');
        $committee->members()->create(['user_id' => $absentMember->id]);
        $meeting->attendees()->create(['user_id' => $absentMember->id, 'attended' => false]);

        $this->actingAs($absentMember, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertStatus(422);
    }

    public function test_meeting_cannot_be_deleted_once_a_decision_is_recorded(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, , , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'defer'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('defer', ['comment' => 'تأجيل']))
            ->assertCreated();

        $admin = $this->userWithRole('R08');
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/meetings/{$meeting->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);
    }

    /** @return array{0: User, 1: User, 2: Committee, 3: Meeting, 4: MeetingRequest} */
    private function committeeMeetingWithAgendaItem(): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');

        $committee = Committee::create(['name_ar' => 'لجنة المشتريات']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع اتخاذ القرار',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);
        $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);

        $requestRecord = $this->requestAtCommitteeStage();
        $agendaItem = $meeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]);
        // Stage 82 — [D] Art. 85's study sequence now gates voting; see
        // Tests\RunsStudySequence for why it is written directly here.
        $this->completeStudySequence($agendaItem);

        return [$head, $member, $committee, $meeting, $agendaItem];
    }

    private function requestAtCommitteeStage(): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب معروض على اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'submitted_at' => now(),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
