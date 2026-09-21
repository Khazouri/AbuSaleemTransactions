<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MeetingController;
use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PassesControlGates;
use Tests\RunsStudySequence;
use Tests\SitsOnCommittee;
use Tests\TestCase;

/**
 * Stage 99 — [D] Appendix 6 rows 8–11: what the matrix gives اللجنة
 * collectively and العضو القانوني as a member, rather than the chair alone.
 */
class CommitteeOwnActsTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;
    use RunsStudySequence;
    use SitsOnCommittee;

    public function test_the_chair_adopts_a_non_empty_agenda_once_and_nobody_else_may(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, $member, $meeting] = $this->sitting();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/adopt")
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن اعتماد جدول أعمال فارغ.');

        $this->agendaItem($meeting);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/adopt")
            ->assertForbidden();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/adopt")
            ->assertOk()
            ->assertJsonPath('data.agenda_adopted_by.id', $head->id);

        $this->assertNotNull($meeting->refresh()->agenda_adopted_at);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/adopt")
            ->assertStatus(422);
    }

    public function test_deliberation_waits_for_the_agendas_adoption(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , $meeting] = $this->sitting();
        $item = $this->agendaItem($meeting);

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/state", ['item_state' => 'discussion'])
            ->assertStatus(422)
            ->assertJsonPath('message', MeetingController::AGENDA_NOT_ADOPTED);

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/study-sequence", ['step' => 'subject_presented', 'done' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', MeetingController::AGENDA_NOT_ADOPTED);

        $this->actingAs($head, 'sanctum')->postJson("/api/meetings/{$meeting->id}/agenda/adopt")->assertOk();

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/state", ['item_state' => 'discussion'])
            ->assertOk();
    }

    public function test_the_adopted_agenda_is_fixed_except_for_an_emerging_item(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , $meeting] = $this->sitting();
        $item = $this->agendaItem($meeting);
        $meeting->update(['agenda_adopted_at' => now()]);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['item_type' => 'administrative', 'subject' => 'بند إداري'])
            ->assertStatus(422)
            ->assertJsonPath('message', MeetingController::AGENDA_ALREADY_ADOPTED);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['item_type' => 'emerging', 'subject' => 'موضوع مستجد'])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->deleteJson("/api/meetings/{$meeting->id}/agenda/{$item->id}")
            ->assertStatus(422);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/apply-order")
            ->assertStatus(422);

        $this->assertDatabaseHas('meeting_requests', ['id' => $item->id]);
    }

    public function test_a_seated_attending_legal_member_deliberates_and_votes(): void
    {
        $this->seed(DatabaseSeeder::class);
        [, , $meeting] = $this->sitting();
        $legal = $this->userWithRole('R11');
        $this->seatOnMeeting($meeting, $legal, ['seat' => 'legal']);
        $meeting->attendees()->create(['user_id' => $legal->id, 'attended' => true]);
        $meeting->update(['agenda_adopted_at' => now()]);
        $item = $this->completeStudySequence($this->agendaItem($meeting));

        $this->actingAs($legal, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/notes", ['note' => 'ملاحظة قانونية على البند'])
            ->assertCreated();

        $this->actingAs($legal, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/votes", ['vote' => 'approve'])
            ->assertCreated();

        $this->assertDatabaseHas('votes', ['meeting_request_id' => $item->id, 'user_id' => $legal->id, 'vote' => 'approve']);
    }

    public function test_the_legal_member_records_a_note_on_the_draft_minutes_only(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , $meeting] = $this->sitting();
        $legal = $this->userWithRole('R11');
        $this->seatOnMeeting($meeting, $legal, ['seat' => 'legal']);

        $this->actingAs($head, 'sanctum')->postJson("/api/meetings/{$meeting->id}/minutes/generate")->assertOk();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/legal-review", ['note' => 'لا ملاحظة'])
            ->assertForbidden();

        $this->actingAs($legal, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/legal-review", ['note' => 'السند المذكور في البند الأول صحيح.'])
            ->assertOk()
            ->assertJsonPath('data.legal_review_note', 'السند المذكور في البند الأول صحيح.')
            ->assertJsonPath('data.legal_reviewed_by.id', $legal->id);

        $meeting->meetingMinutes()->update(['status' => MeetingMinutes::STATUS_PENDING_SIGNATURES]);

        $this->actingAs($legal, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/legal-review", ['note' => 'متأخرة'])
            ->assertStatus(422);
    }

    public function test_the_legal_seat_is_bound_to_the_legal_member_role(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, $member, $meeting] = $this->sitting();
        $legal = $this->userWithRole('R11');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/committees/{$meeting->committee_id}/members", ['user_id' => $member->id, 'seat' => 'legal'])
            ->assertStatus(422)
            ->assertJsonPath('errors.user_id.0', 'مقعد العضو القانوني مقصور على من يحمل دور العضو القانوني.');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/committees/{$meeting->committee_id}/members", ['user_id' => $legal->id, 'seat' => 'legal'])
            ->assertCreated();
    }

    /**
     * The one path Appendix 8's gate did not already close: every attendee
     * recorded absent under a count-0 quorum passes all sixteen checks, and
     * Stage 36 then approved the محضر with nobody's signature on it.
     */
    public function test_minutes_nobody_attended_are_never_approved_without_a_signature(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, $member, $meeting] = $this->sitting(attended: false);
        $meeting->committee->update(['quorum_type' => 'count', 'quorum_count' => 0]);

        $this->actingAs($head, 'sanctum')->postJson("/api/meetings/{$meeting->id}/minutes/generate")->assertOk();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/review", $this->minutesApprovalPayload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يعتمد المحضر دون حضور مسجل من أعضاء اللجنة يوقعون عليه.');

        $this->assertSame('draft', $meeting->meetingMinutes()->value('status'));
    }

    /** @return array{0: User, 1: User, 2: Meeting} */
    private function sitting(bool $attended = true): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $this->seatOn($committee, $head, ['is_head' => true]);
        $this->seatOn($committee, $member);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع اللجنة',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => $attended]);
        $meeting->attendees()->create(['user_id' => $member->id, 'attended' => $attended]);

        return [$head, $member, $meeting];
    }

    private function agendaItem(Meeting $meeting): MeetingRequest
    {
        $requestRecord = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب ترقية',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $this->userWithRole('R01')->id,
            'submitted_at' => now(),
        ]);

        return $meeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
