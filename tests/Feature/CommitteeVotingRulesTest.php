<?php

namespace Tests\Feature;

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
use Tests\TestCase;

/**
 * Stage 73 — [D] Appendix 65's بطاقة تعريف اللجنة, and Appendix 64's rule
 * that the system may not invent a quorum or a majority for itself.
 *
 * The load-bearing assertions here are the two that pin down what changed:
 * an untranscribed committee reports NO quorum (where it used to report
 * ceil(members / 2)), and a "more than half" rule over four members requires
 * three — the case where the transcribed rule and the old invented one give
 * different answers, so a relabelling would fail it.
 */
class CommitteeVotingRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_committee_with_no_transcribed_rules_has_no_quorum_and_blocks_convening(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee, $members] = $this->committeeWithMembers(2, rules: []);
        $meeting = $this->scheduleMeeting($committee, $head);
        foreach ($members as $member) {
            $this->invite($meeting, $member, 'confirmed');
        }

        $readiness = $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/readiness")
            ->assertOk();

        // The pre-Stage-73 behaviour would have answered 1 here.
        $this->assertNull($readiness->json('data.quorum_required'));
        $this->assertNull($readiness->json('data.quorum_met'));
        $this->assertNull($readiness->json('data.quorum_rule'));
        $this->assertFalse($readiness->json('data.ready'));
        $this->assertContains(
            'committee_rules_not_recorded',
            collect($readiness->json('data.exceptions'))->pluck('code'),
        );

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/convene")
            ->assertStatus(422);
    }

    public function test_a_transcribed_count_quorum_is_applied_as_written(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee, $members] = $this->committeeWithMembers(4, rules: [
            'quorum_type' => 'count',
            'quorum_count' => 3,
            'quorum_text' => 'لا يصح الاجتماع بأقل من ثلاثة أعضاء',
        ]);
        $meeting = $this->scheduleMeeting($committee, $head);
        $this->invite($meeting, $members[0], 'confirmed');
        $this->invite($meeting, $members[1], 'confirmed');
        $this->invite($meeting, $members[2], 'declined');
        $this->invite($meeting, $members[3], 'declined');

        $readiness = $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/readiness")
            ->assertOk();

        $this->assertSame(3, $readiness->json('data.quorum_required'));
        $this->assertSame(2, $readiness->json('data.quorum_confirmed'));
        $this->assertFalse($readiness->json('data.quorum_met'));
        $this->assertSame(
            'لا يصح الاجتماع بأقل من ثلاثة أعضاء',
            $readiness->json('data.quorum_rule.quorum_text'),
        );
    }

    /**
     * The distinction the comparator exists for: "أكثر من نصف الأعضاء" over
     * four members is three, while the invented ceil(4 / 2) this stage
     * removed was two.
     */
    public function test_a_more_than_half_quorum_over_four_members_requires_three(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee, $members] = $this->committeeWithMembers(4, rules: [
            'quorum_type' => 'fraction',
            'quorum_numerator' => 1,
            'quorum_denominator' => 2,
            'quorum_comparator' => 'more_than',
        ]);
        $meeting = $this->scheduleMeeting($committee, $head);
        $this->invite($meeting, $members[0], 'confirmed');
        $this->invite($meeting, $members[1], 'confirmed');
        $this->invite($meeting, $members[2], 'declined');
        $this->invite($meeting, $members[3], 'declined');

        $this->assertSame(3, $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/readiness")
            ->assertOk()
            ->json('data.quorum_required'));
    }

    public function test_an_at_least_half_quorum_over_four_members_requires_two(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee, $members] = $this->committeeWithMembers(4, rules: [
            'quorum_type' => 'fraction',
            'quorum_numerator' => 1,
            'quorum_denominator' => 2,
            'quorum_comparator' => 'at_least',
        ]);
        $meeting = $this->scheduleMeeting($committee, $head);
        foreach ($members as $member) {
            $this->invite($meeting, $member, 'confirmed');
        }

        $this->assertSame(2, $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/readiness")
            ->assertOk()
            ->json('data.quorum_required'));
    }

    public function test_the_identity_card_round_trips_through_the_committee_api(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        $created = $this->actingAs($head, 'sanctum')
            ->postJson('/api/committees', [
                'name_ar' => 'لجنة شؤون الموظفين',
                'formation_decision_number' => '44',
                'formation_decision_date' => '2026-01-15',
                'term_note' => 'سنة قابلة للتجديد',
                'legal_basis' => 'قانون علاقات العمل ولائحته التنفيذية',
                'minutes_approval_body' => 'عميد البلدية',
                'voting_rights_note' => 'لكل عضو صوت واحد عدا المقرر',
                'minutes_signature_rule' => 'يوقع المحضر الرئيس والمقرر وجميع الحاضرين',
                'recusal_rules' => 'يتنحى العضو في موضوع يخص قريبًا حتى الدرجة الرابعة',
                'quorum_type' => 'fraction',
                'quorum_numerator' => 2,
                'quorum_denominator' => 3,
                'quorum_comparator' => 'at_least',
                'quorum_text' => 'ثلثا الأعضاء',
                'majority_type' => 'fraction',
                'majority_basis' => 'present',
                'majority_numerator' => 1,
                'majority_denominator' => 2,
                'majority_comparator' => 'more_than',
                'majority_text' => 'أغلبية الحاضرين',
                'tie_break' => 'chair_casting_vote',
                'tie_break_text' => 'عند التساوي يرجح الجانب الذي فيه الرئيس',
            ])
            ->assertCreated()
            ->assertJsonPath('data.quorum_text', 'ثلثا الأعضاء')
            ->assertJsonPath('data.formation_decision_number', '44')
            ->assertJsonPath('data.formation_decision_date', '2026-01-15')
            ->assertJsonPath('data.tie_break', 'chair_casting_vote')
            ->assertJsonPath('data.rules_recorded', true);

        $id = $created->json('data.id');

        $this->actingAs($head, 'sanctum')
            ->getJson('/api/committees')
            ->assertOk()
            ->assertJsonPath('data.0.minutes_approval_body', 'عميد البلدية');

        // A fraction with no comparator is refused rather than defaulted:
        // "لا يقل عن النصف" and "أكثر من النصف" are different numbers, and
        // picking one for the reader is exactly what Appendix 64 forbids.
        $this->actingAs($head, 'sanctum')
            ->putJson("/api/committees/{$id}", [
                'name_ar' => 'لجنة شؤون الموظفين',
                'quorum_type' => 'fraction',
                'quorum_numerator' => 1,
                'quorum_denominator' => 2,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quorum_comparator');
    }

    public function test_a_transcribed_majority_refuses_an_outcome_that_leads_without_reaching_it(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $members, $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem(4, [
            'majority_type' => 'fraction',
            'majority_basis' => 'votes_cast',
            'majority_numerator' => 1,
            'majority_denominator' => 2,
            'majority_comparator' => 'more_than',
            'majority_text' => 'أغلبية الأصوات المدلى بها',
        ]);

        $this->castVote($members[0], $meeting, $agendaItem, 'defer');
        $this->castVote($members[1], $meeting, $agendaItem, 'defer');
        $this->castVote($members[2], $meeting, $agendaItem, 'reject');
        $this->castVote($members[3], $meeting, $agendaItem, 'abstain');

        // defer leads with 2 of 4 votes cast; the transcribed rule needs 3.
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", ['comment' => 'تأجيل'])
            ->assertStatus(422);

        $this->assertSame(0, Decision::count());
    }

    public function test_a_transcribed_majority_records_the_outcome_once_it_is_reached(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $members, $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem(4, [
            'majority_type' => 'fraction',
            'majority_basis' => 'votes_cast',
            'majority_numerator' => 1,
            'majority_denominator' => 2,
            'majority_comparator' => 'more_than',
        ]);

        $this->castVote($members[0], $meeting, $agendaItem, 'defer');
        $this->castVote($members[1], $meeting, $agendaItem, 'defer');
        $this->castVote($members[2], $meeting, $agendaItem, 'defer');
        $this->castVote($members[3], $meeting, $agendaItem, 'reject');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", ['comment' => 'تأجيل'])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'defer');
    }

    public function test_a_chair_casting_vote_resolves_a_tie_the_default_rule_refuses(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $members, $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem(2, []);

        // The chair is the committee's own head seat, and votes with the
        // members: `defer` and `reject` end level at one vote each.
        $this->castVote($head, $meeting, $agendaItem, 'defer');
        $this->castVote($members[0], $meeting, $agendaItem, 'reject');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", ['comment' => 'قرار'])
            ->assertStatus(422);

        $meeting->committee->update([
            'tie_break' => 'chair_casting_vote',
            'tie_break_text' => 'عند تساوي الأصوات يرجح الجانب الذي فيه رئيس اللجنة',
        ]);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", ['comment' => 'قرار'])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'defer');
    }

    /**
     * The ⚠ this stage carries: a sitting already held must keep being judged
     * by the rules that were in force when it was held.
     */
    public function test_convening_freezes_the_rules_against_a_later_edit_of_the_card(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee, $members] = $this->committeeWithMembers(4, rules: [
            'quorum_type' => 'count',
            'quorum_count' => 2,
        ]);
        $meeting = $this->scheduleMeeting($committee, $head);
        foreach ($members as $member) {
            $this->invite($meeting, $member, 'confirmed');
        }

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/convene", ['reason' => 'اجتماع منعقد'])
            ->assertOk();

        $this->assertSame(2, $meeting->refresh()->voting_rules_snapshot['quorum_count']);

        $committee->update(['quorum_count' => 4]);

        $this->assertSame(2, $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/readiness")
            ->assertOk()
            ->json('data.quorum_required'));
    }

    public function test_generated_minutes_carry_the_applied_rule_and_the_identity_card(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee, $members] = $this->committeeWithMembers(2, rules: [
            'quorum_type' => 'count',
            'quorum_count' => 2,
            'quorum_text' => 'عضوان على الأقل',
            'formation_decision_number' => '12',
            'minutes_approval_body' => 'عميد البلدية',
        ]);
        $meeting = $this->scheduleMeeting($committee, $head);
        foreach ($members as $member) {
            $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);
        }

        $minutes = $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/generate")
            ->assertOk();

        $this->assertSame(2, $minutes->json('data.content.attendance.quorum_required'));
        $this->assertTrue($minutes->json('data.content.attendance.quorum_met'));
        $this->assertSame('عضوان على الأقل', $minutes->json('data.content.attendance.quorum_rule.quorum_text'));
        $this->assertSame('12', $minutes->json('data.content.committee.formation_decision_number'));
        $this->assertSame('عميد البلدية', $minutes->json('data.content.committee.minutes_approval_body'));
    }

    public function test_minutes_for_an_untranscribed_committee_report_no_quorum_rather_than_an_invented_one(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee, $members] = $this->committeeWithMembers(2, rules: []);
        $meeting = $this->scheduleMeeting($committee, $head);
        foreach ($members as $member) {
            $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);
        }

        $minutes = $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/generate")
            ->assertOk();

        $this->assertNull($minutes->json('data.content.attendance.quorum_required'));
        $this->assertNull($minutes->json('data.content.attendance.quorum_met'));
        $this->assertNull($minutes->json('data.content.attendance.quorum_rule'));
        // Attendance itself is still counted — only the rule is missing.
        $this->assertSame(2, $minutes->json('data.content.attendance.quorum_present'));
    }

    // --- Fixture helpers -----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $rules
     * @return array{0: Committee, 1: User[]}
     */
    private function committeeWithMembers(int $count, array $rules): array
    {
        $committee = Committee::create(['name_ar' => 'لجنة اختبار النصاب'] + $rules);
        $members = [];
        for ($i = 0; $i < $count; $i++) {
            $user = $this->userWithRole('R04');
            $committee->members()->create(['user_id' => $user->id]);
            $members[] = $user;
        }

        return [$committee, $members];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{0: User, 1: User[], 2: Meeting, 3: MeetingRequest}
     */
    private function committeeMeetingWithAgendaItem(int $memberCount, array $rules): array
    {
        $head = $this->userWithRole('R03');
        [$committee, $members] = $this->committeeWithMembers($memberCount, $rules);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع اختبار الأغلبية',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);
        foreach ($members as $member) {
            $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);
        }

        $requestRecord = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب معروض على اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'submitted_at' => now(),
        ]);

        $agendaItem = $meeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]);

        return [$head, $members, $meeting, $agendaItem];
    }

    private function castVote(User $voter, Meeting $meeting, MeetingRequest $agendaItem, string $vote): void
    {
        $this->actingAs($voter, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => $vote])
            ->assertCreated();
    }

    private function scheduleMeeting(Committee $committee, User $creator): Meeting
    {
        return Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع اختبار النصاب',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(2),
            'created_by_user_id' => $creator->id,
        ]);
    }

    private function invite(Meeting $meeting, User $user, string $invitationStatus): void
    {
        $meeting->attendees()->create([
            'user_id' => $user->id,
            'invitation_status' => $invitationStatus,
            'responded_at' => $invitationStatus === 'pending' ? null : now(),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
