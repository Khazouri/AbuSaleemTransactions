<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\Department;
use App\Models\Meeting;
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

/**
 * Stage 45 — [D] Art. 10's fixed 5-seat committee roster: named seats are
 * additive over the pre-existing open R03/R04 membership, and filling the
 * مندوب الخدمة المدنية (ministry_delegate) seat must never, on its own,
 * short-circuit the separate central-ministry approval a request's own
 * decision_grade requires (Art. 14).
 */
class CommitteeSeatRosterTest extends TestCase
{
    use RecordsStructuredDecisions;
    use RefreshDatabase;
    use RunsStudySequence;

    public function test_a_committees_five_seats_are_individually_identifiable(): void
    {
        $this->seed(DatabaseSeeder::class);

        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $chair = $this->userWithRole('R03');
        $legal = $this->userWithRole('R11');
        $unseated = $this->userWithRole('R04');

        // Membership gate — editing a committee's roster needs a seat on it.
        // Built directly here because Committee::create() above bypasses
        // CommitteeController::store(), which is what seats a creator in
        // production. The chair's first call below then PROMOTES this row to
        // the chair seat rather than adding a second one for the same person.
        CommitteeMember::create(['committee_id' => $committee->id, 'user_id' => $chair->id]);

        $this->actingAs($chair, 'sanctum')
            ->postJson("/api/committees/{$committee->id}/members", [
                'user_id' => $chair->id,
                'seat' => 'chair',
            ])
            ->assertCreated()
            ->assertJsonPath('data.seat', 'chair')
            ->assertJsonPath('data.is_head', true);

        $this->actingAs($chair, 'sanctum')
            ->postJson("/api/committees/{$committee->id}/members", [
                'user_id' => $legal->id,
                'seat' => 'legal',
            ])
            ->assertCreated();

        // A plain member with no named seat is still just a member.
        $this->actingAs($chair, 'sanctum')
            ->postJson("/api/committees/{$committee->id}/members", ['user_id' => $unseated->id])
            ->assertCreated()
            ->assertJsonPath('data.seat', null);

        $response = $this->actingAs($chair, 'sanctum')->getJson('/api/committees')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $committee->id);

        $this->assertSame($chair->id, $row['seats']['chair']['user']['id']);
        $this->assertSame($legal->id, $row['seats']['legal']['user']['id']);
        $this->assertNull($row['seats']['hr_director']);
        $this->assertNull($row['seats']['ministry_delegate']);
        $this->assertNull($row['seats']['rapporteur']);
        $this->assertCount(3, $row['members']);
    }

    public function test_a_seat_can_only_be_held_by_one_member_at_a_time(): void
    {
        $this->seed(DatabaseSeeder::class);

        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $chair = $this->userWithRole('R03');
        $rival = $this->userWithRole('R04');

        $committee->members()->create(['user_id' => $chair->id, 'seat' => 'chair', 'is_head' => true]);

        $this->actingAs($chair, 'sanctum')
            ->postJson("/api/committees/{$committee->id}/members", [
                'user_id' => $rival->id,
                'seat' => 'chair',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('seat');

        $this->assertSame(
            $chair->id,
            CommitteeMember::where('committee_id', $committee->id)->where('seat', 'chair')->value('user_id'),
        );
    }

    public function test_an_unknown_seat_value_is_rejected(): void
    {
        $this->seed(DatabaseSeeder::class);

        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $chair = $this->userWithRole('R03');
        $committee->members()->create(['user_id' => $chair->id, 'seat' => 'chair', 'is_head' => true]);

        $someone = $this->userWithRole('R04');

        $this->actingAs($chair, 'sanctum')
            ->postJson("/api/committees/{$committee->id}/members", [
                'user_id' => $someone->id,
                'seat' => 'treasurer',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('seat');
    }

    public function test_multiple_unseated_members_are_not_blocked_by_each_other(): void
    {
        $this->seed(DatabaseSeeder::class);

        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $chair = $this->userWithRole('R03');
        $committee->members()->create(['user_id' => $chair->id, 'seat' => 'chair', 'is_head' => true]);

        $first = $this->userWithRole('R04');
        $second = $this->userWithRole('R04');

        $this->actingAs($chair, 'sanctum')
            ->postJson("/api/committees/{$committee->id}/members", ['user_id' => $first->id])
            ->assertCreated();

        $this->actingAs($chair, 'sanctum')
            ->postJson("/api/committees/{$committee->id}/members", ['user_id' => $second->id])
            ->assertCreated();
    }

    /**
     * The load-bearing guarantee this stage adds: seating (and voting as)
     * مندوب الخدمة المدنية is display/voting membership only (Art. 14) — it
     * must never itself decide whether a request also needs the separate
     * central ministry approval that `Request::requiresMinistryApproval()`
     * governs. Both scenarios below share the exact same committee and the
     * same ministry-delegate seat holder voting the exact same way; the only
     * thing that differs is the request's own decision_grade, and that is
     * the only thing allowed to change the outcome.
     */
    public function test_the_ministry_delegate_seats_vote_never_substitutes_for_the_separate_ministry_approval(): void
    {
        $this->seed(DatabaseSeeder::class);

        $localGovernanceMinistry = WorkflowStage::where('code', 'local_governance_ministry')->firstOrFail();
        // Stage 57 removed competent_authority as a distinct checkpoint — a
        // bypass now lands directly at final_approval_archiving.
        $finalApprovalArchiving = WorkflowStage::where('code', 'final_approval_archiving')->firstOrFail();

        // Grade at the threshold: ministry approval is still required.
        $requiresMinistry = $this->decideAndAdvanceToAuthorityCheckpoint(decisionGrade: 10);
        $this->assertSame($localGovernanceMinistry->id, $requiresMinistry->fresh()->current_stage_id);
        $this->assertTrue($requiresMinistry->fresh()->requiresMinistryApproval());

        // Grade below the threshold, same committee, same ministry-delegate
        // vote: the request bypasses ministry on its own grade, not because
        // of who sat on or voted with the committee.
        $bypassesMinistry = $this->decideAndAdvanceToAuthorityCheckpoint(decisionGrade: 5);
        $this->assertSame($finalApprovalArchiving->id, $bypassesMinistry->fresh()->current_stage_id);
        $this->assertSame('final_approved', $bypassesMinistry->fresh()->status->code);
        $this->assertFalse($bypassesMinistry->fresh()->requiresMinistryApproval());
    }

    private function decideAndAdvanceToAuthorityCheckpoint(int $decisionGrade): Request
    {
        $chair = $this->userWithRole('R03');
        $delegate = $this->userWithRole('R04');

        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين '.$decisionGrade]);
        $committee->members()->create(['user_id' => $chair->id, 'seat' => 'chair', 'is_head' => true]);
        $committee->members()->create(['user_id' => $delegate->id, 'seat' => 'ministry_delegate']);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع اتخاذ القرار',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $chair->id,
        ]);
        $meeting->attendees()->create(['user_id' => $chair->id, 'attended' => true]);
        $meeting->attendees()->create(['user_id' => $delegate->id, 'attended' => true]);

        $requestRecord = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب معروض على اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'submitted_at' => now(),
            'decision_grade' => $decisionGrade,
        ]);
        $agendaItem = $meeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]);
        // Stage 82 — [D] Art. 85's study sequence now gates voting; see
        // Tests\RunsStudySequence for why it is written directly here.
        $this->completeStudySequence($agendaItem);

        $this->actingAs($delegate, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();
        $this->actingAs($chair, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();

        $this->actingAs($chair, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('approve'))
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'approve');

        $admin = $this->userWithRole('R05');
        $this->actingAs($admin, 'sanctum')
            ->post("/api/approvals/admin-manager/{$requestRecord->id}", [])
            ->assertOk();

        return $requestRecord;
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
