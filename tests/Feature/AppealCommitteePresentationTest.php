<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AppealStatus;
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

/**
 * Stage 63, Track J — an appeal at `legal_review` rides the existing Stage
 * 31/21/25/48 agenda/vote/signature/CoI machinery via a new
 * `item_type='appeal'`, and is decided with Art. 75 point 5's own
 * 5-outcome vocabulary instead of the ordinary employee_request one — see
 * AGENT_NOTES.md's Stage 63 plan for the full guard-by-guard inventory this
 * test suite is meant to cover.
 */
class AppealCommitteePresentationTest extends TestCase
{
    use RecordsStructuredDecisions;
    use RefreshDatabase;
    use RunsStudySequence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_nomination_is_refused_before_legal_review_and_succeeds_once_reached(): void
    {
        [$head, , , $meeting] = $this->committeeMeetingFixture();
        $appellant = $this->userWithRole('R01');
        $appeal = $this->appealAt($appellant, 'file_assembly');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", [
                'item_type' => 'appeal',
                'appeal_id' => $appeal->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('meeting_requests', ['appeal_id' => $appeal->id]);

        $appeal->update(['appeal_status_id' => AppealStatus::where('code', 'legal_review')->value('id')]);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", [
                'item_type' => 'appeal',
                'appeal_id' => $appeal->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.item_type', 'appeal')
            ->assertJsonPath('data.appeal.id', $appeal->id)
            ->assertJsonPath('data.appeal.appellant.id', $appellant->id);
    }

    public function test_appeal_options_excludes_a_non_legal_review_appeal_and_one_already_nominated(): void
    {
        [$head, , , $meeting] = $this->committeeMeetingFixture();
        $appellant = $this->userWithRole('R01');

        $notReady = $this->appealAt($appellant, 'file_assembly');
        $ready = $this->appealAt($appellant, 'legal_review');

        $listed = $this->actingAs($head, 'sanctum')
            ->getJson('/api/meetings/appeal-options')
            ->assertOk()
            ->json('data');

        $ids = array_column($listed, 'id');
        $this->assertNotContains($notReady->id, $ids);
        $this->assertContains($ready->id, $ids);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", [
                'item_type' => 'appeal',
                'appeal_id' => $ready->id,
            ])
            ->assertCreated();

        $listedAfter = $this->actingAs($head, 'sanctum')
            ->getJson('/api/meetings/appeal-options')
            ->assertOk()
            ->json('data');

        $this->assertNotContains($ready->id, array_column($listedAfter, 'id'));
    }

    public function test_voting_on_an_appeal_item_reuses_the_same_membership_attendance_and_conflict_rules(): void
    {
        [$head, $member, $committee, $meeting, $agendaItem] = $this->committeeMeetingWithAppealAgendaItem();

        $stranger = $this->userWithRole('R04');

        // Membership gate — with no seat this is a 404: the sitting is not
        // visible to them, so there is nothing to explain. Once seated below
        // they get the reasoned 422 for not having attended.
        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'appeal_accept'])
            ->assertStatus(404);

        $committee->members()->create(['user_id' => $stranger->id]);

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'appeal_accept'])
            ->assertStatus(422);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'appeal_accept'])
            ->assertCreated()
            ->assertJsonPath('data.vote', 'appeal_accept');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/conflict-of-interest", [])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'appeal_accept'])
            ->assertStatus(422);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/notes", ['note' => 'ملاحظة'])
            ->assertStatus(422);
    }

    public function test_a_tied_appeal_vote_and_a_zero_vote_tally_are_both_refused(): void
    {
        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAppealAgendaItem();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('appeal_accept', ['comment' => 'سبب']))
            ->assertStatus(422);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'appeal_accept'])
            ->assertCreated();
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'appeal_reject'])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('appeal_accept', ['comment' => 'سبب']))
            ->assertStatus(422);

        $this->assertDatabaseMissing('decisions', ['meeting_request_id' => $agendaItem->id]);
    }

    public function test_an_empty_comment_is_refused_when_recording_an_appeal_decision(): void
    {
        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAppealAgendaItem();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'appeal_accept'])
            ->assertCreated();
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'appeal_accept'])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('appeal_accept'))
            ->assertStatus(422);

        $this->assertDatabaseMissing('decisions', ['meeting_request_id' => $agendaItem->id]);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('appeal_accept', ['comment' => 'سبب القرار']))
            ->assertCreated();
    }

    public function test_appeal_accept_advances_the_appeal_without_touching_the_original_request(): void
    {
        $this->assertOutcomeAdvancesAppealAlone('appeal_accept', 'votes_appeal_accept_count');
    }

    public function test_appeal_partial_accept_advances_the_appeal_without_touching_the_original_request(): void
    {
        $this->assertOutcomeAdvancesAppealAlone('appeal_partial_accept', 'votes_appeal_partial_accept_count');
    }

    public function test_appeal_reject_advances_the_appeal_without_touching_the_original_request(): void
    {
        $this->assertOutcomeAdvancesAppealAlone('appeal_reject', 'votes_appeal_reject_count');
    }

    public function test_appeal_refer_advances_the_appeal_without_touching_the_original_request(): void
    {
        $this->assertOutcomeAdvancesAppealAlone('appeal_refer', 'votes_appeal_refer_count');
    }

    public function test_appeal_redo_advances_the_appeal_without_touching_the_original_request(): void
    {
        $this->assertOutcomeAdvancesAppealAlone('appeal_redo', 'votes_appeal_redo_count');
    }

    /**
     * Shared by the five outcome tests above: each of Art. 75 point 5's
     * outcomes must advance the appeal alone, never the original request —
     * that mutation is Stage 64's own, deliberately separate, scope.
     */
    private function assertOutcomeAdvancesAppealAlone(string $outcome, string $countColumn): void
    {
        [$head, $member, , $meeting, $agendaItem, $appeal] = $this->committeeMeetingWithAppealAgendaItem();

        $originalRequest = $appeal->originalRequest->fresh();
        $originalStatusId = $originalRequest->status_id;
        $originalStageId = $originalRequest->current_stage_id;

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => $outcome])
            ->assertCreated();
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => $outcome])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload($outcome, ['comment' => 'سبب القرار']))
            ->assertCreated()
            ->assertJsonPath('data.outcome', $outcome)
            ->assertJsonPath("data.{$countColumn}", 2);

        $this->assertSame('committee_presentation', $appeal->fresh()->status->code);

        $originalRequest->refresh();
        $this->assertSame($originalStatusId, $originalRequest->status_id);
        $this->assertSame($originalStageId, $originalRequest->current_stage_id);

        $this->assertDatabaseHas('decisions', [
            'meeting_request_id' => $agendaItem->id,
            'outcome' => $outcome,
            $countColumn => 2,
        ]);
    }

    public function test_register_and_pending_endpoints_surface_appeal_context(): void
    {
        [$head, $member, , $meeting, $agendaItem, $appeal] = $this->committeeMeetingWithAppealAgendaItem();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'appeal_accept'])
            ->assertCreated();

        $pending = $this->actingAs($member, 'sanctum')
            ->getJson('/api/decisions/pending')
            ->assertOk()
            ->json('data');

        $pendingRow = collect($pending)->firstWhere('id', $agendaItem->id);
        $this->assertNotNull($pendingRow);
        $this->assertSame($appeal->id, $pendingRow['appeal']['id']);
        $this->assertSame($appeal->appellant_user_id, $pendingRow['appeal']['appellant']['id']);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'appeal_accept'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('appeal_accept', ['comment' => 'سبب القرار']))
            ->assertCreated();

        $register = $this->actingAs($head, 'sanctum')
            ->getJson('/api/decisions')
            ->assertOk()
            ->json('data');

        $registerRow = collect($register)->firstWhere('context.agenda_item_id', $agendaItem->id);
        $this->assertNotNull($registerRow);
        $this->assertSame($appeal->id, $registerRow['context']['appeal']['id']);
        $this->assertSame(
            $appeal->originalRequest->reference_number,
            $registerRow['context']['appeal']['original_request']['reference_number'],
        );
    }

    /** @return array{0: User, 1: User, 2: Committee, 3: Meeting} */
    private function committeeMeetingFixture(): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');

        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع عرض التظلمات',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);
        $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);

        return [$head, $member, $committee, $meeting];
    }

    /** @return array{0: User, 1: User, 2: Committee, 3: Meeting, 4: MeetingRequest, 5: Appeal} */
    private function committeeMeetingWithAppealAgendaItem(): array
    {
        [$head, $member, $committee, $meeting] = $this->committeeMeetingFixture();

        $appellant = $this->userWithRole('R01');
        $appeal = $this->appealAt($appellant, 'legal_review');

        $agendaItem = $meeting->agendaItems()->create(['appeal_id' => $appeal->id, 'item_type' => 'appeal', 'agenda_order' => 1]);
        // Stage 82 — [D] Art. 85's study sequence now gates voting; see
        // Tests\RunsStudySequence for why it is written directly here.
        $this->completeStudySequence($agendaItem);

        return [$head, $member, $committee, $meeting, $agendaItem, $appeal];
    }

    private function requestFixture(User $creator): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب صدر بشأنه قرار',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'final_approved')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'final_approval_archiving')->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now(),
        ]);
    }

    private function appealAt(User $appellant, string $statusCode): Appeal
    {
        $target = $this->requestFixture($appellant);

        return Appeal::create([
            'appellant_user_id' => $appellant->id,
            'original_request_id' => $target->id,
            'original_decision_reference' => 'قرار اعتماد نهائي',
            'known_at' => now()->subDay(),
            'appeal_reasons' => 'القرار خالف الإجراءات المتبعة.',
            'final_request' => 'إعادة النظر في القرار.',
            'appeal_status_id' => AppealStatus::where('code', $statusCode)->value('id'),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
