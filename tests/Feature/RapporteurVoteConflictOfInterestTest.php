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
use Tests\RunsStudySequence;
use Tests\TestCase;

/**
 * Stage 48 — [D] Art. 11/15/18: the meeting's rapporteur does not vote unless
 * the committee's own tashkil decision grants it, and a member with a stake
 * in an item must formally recuse before deliberation.
 */
class RapporteurVoteConflictOfInterestTest extends TestCase
{
    use RefreshDatabase;
    use RunsStudySequence;

    public function test_the_meetings_rapporteur_cannot_vote_unless_the_committees_tashkil_grants_it(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, $committee, $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();
        $meeting->update(['rapporteur_user_id' => $member->id]);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'مقرر الاجتماع لا يشارك في التصويت إلا إذا نص قرار تشكيل اللجنة على خلاف ذلك.');

        // The pending-votes worklist must agree with the guard above.
        $this->actingAs($member, 'sanctum')
            ->getJson('/api/decisions/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Once the committee's tashkil decision grants dual status, the same
        // rapporteur may vote, and the worklist offers the item too.
        $committee->update(['rapporteur_votes' => true]);

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/decisions/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();

        // The head, who is not the rapporteur, is unaffected either way.
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();
    }

    public function test_declaring_a_conflict_of_interest_blocks_both_voting_and_the_discussion_feed(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/conflict-of-interest", [
                'reason' => 'الموظف صاحب الطلب هو ابن عمي',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $member->id);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'تم إعلان تعارض مصالح على هذا البند، ولا يجوز لك التصويت عليه.');

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/notes", ['note' => 'رأيي في الموضوع...'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'تم إعلان تعارض مصالح على هذا البند، لا يجوز المشاركة في مداولته.');

        // A member who has not declared a conflict is unaffected.
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/decisions/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_only_committee_members_may_declare_a_conflict_of_interest(): void
    {
        $this->seed(DatabaseSeeder::class);

        [, , , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $outsider = $this->userWithRole('R04');

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/conflict-of-interest", [])
            ->assertStatus(422);
    }

    public function test_a_role_without_decisions_add_cannot_declare_a_conflict_of_interest(): void
    {
        $this->seed(DatabaseSeeder::class);

        [, , , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $employee = $this->userWithRole('R01');

        $this->actingAs($employee, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/conflict-of-interest", [])
            ->assertStatus(403);
    }

    public function test_conflict_of_interest_declarations_are_recorded_in_the_compiled_minutes(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/conflict-of-interest", [
                'reason' => 'تعارض مصالح مالي',
            ])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/generate")
            ->assertOk()
            ->assertJsonPath('data.content.agenda_items.0.conflict_declarations.0.user', $member->name)
            ->assertJsonPath('data.content.agenda_items.0.conflict_declarations.0.reason', 'تعارض مصالح مالي');
    }

    /** @return array{0: User, 1: User, 2: Committee, 3: Meeting, 4: MeetingRequest} */
    private function committeeMeetingWithAgendaItem(): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');

        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
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
