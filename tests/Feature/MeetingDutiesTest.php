<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\User;
use App\Services\MeetingReadinessService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PassesControlGates;
use Tests\SitsOnCommittee;
use Tests\TestCase;

/**
 * Decision wizard, sub-project 2 — what a member may do on a meeting. Each
 * "blocked" reason must be the very message the endpoint refuses with.
 */
class MeetingDutiesTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;
    use SitsOnCommittee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_adopting_an_empty_agenda_is_blocked_with_the_endpoints_own_refusal(): void
    {
        [$meeting, $seats] = $this->meeting(['status' => 'scheduled']);

        $reason = $this->blockedReason($seats['chair'], $meeting, 'adopt_agenda');
        $this->actingAs($seats['chair'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/adopt")
            ->assertStatus(422)
            ->assertJsonPath('message', $reason);

        MeetingRequest::create(['meeting_id' => $meeting->id, 'agenda_order' => 1, 'item_type' => 'employee_request']);
        $this->assertContains('adopt_agenda', $this->availableActions($seats['chair'], $meeting));
        $this->assertNotContains('adopt_agenda', $this->availableActions($seats['legal'], $meeting));
    }

    public function test_convening_waits_for_every_member_to_accept_the_date(): void
    {
        [$meeting, $seats] = $this->meeting(['status' => Meeting::STATUS_PENDING_CONFIRMATION]);

        $reason = $this->blockedReason($seats['chair'], $meeting, 'convene');
        $this->actingAs($seats['chair'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/convene", ['reason' => 'تجاوز'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', $reason);

        $meeting->update(['status' => 'scheduled']);
        // Not ready (the agenda is empty), so overriding readiness owes a reason.
        $convene = collect($this->duties($seats['chair'], $meeting)['available'])->firstWhere('action', 'convene');
        $this->assertTrue($convene['requires_comment']);
    }

    public function test_a_ready_meeting_convenes_without_a_reason(): void
    {
        $this->mock(MeetingReadinessService::class)
            ->shouldReceive('compute')->andReturn(['ready' => true]);
        [$meeting, $seats] = $this->meeting(['status' => 'scheduled']);

        $convene = collect($this->duties($seats['chair'], $meeting)['available'])->firstWhere('action', 'convene');
        $this->assertFalse($convene['requires_comment']);
    }

    public function test_closing_is_blocked_while_an_item_is_unresolved(): void
    {
        [$meeting, $seats] = $this->meeting(['status' => 'scheduled', 'convened_at' => now()]);
        MeetingRequest::create(['meeting_id' => $meeting->id, 'agenda_order' => 1, 'item_type' => 'employee_request']);

        $reason = $this->blockedReason($seats['chair'], $meeting, 'close');
        $this->actingAs($seats['chair'], 'sanctum')
            ->putJson("/api/meetings/{$meeting->id}", ['status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonPath('message', $reason);
    }

    public function test_approving_minutes_nobody_attended_is_blocked_with_the_review_refusal(): void
    {
        [$meeting, $seats] = $this->meeting(['status' => 'scheduled'], attended: false);
        $this->actingAs($seats['chair'], 'sanctum')->postJson("/api/meetings/{$meeting->id}/minutes/generate")->assertOk();

        $reason = $this->blockedReason($seats['chair'], $meeting, 'approve_minutes');
        $this->actingAs($seats['chair'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/review", $this->minutesApprovalPayload())
            ->assertStatus(422)
            ->assertJsonPath('message', $reason);

        $this->assertContains('return_minutes', $this->availableActions($seats['chair'], $meeting));
    }

    public function test_an_invited_member_answers_the_date_and_their_seat_says_so(): void
    {
        [$meeting, $seats] = $this->meeting(['status' => Meeting::STATUS_PENDING_CONFIRMATION]);

        $this->assertSame(['respond_accept', 'respond_decline'], $this->availableActions($seats['legal'], $meeting));

        $this->actingAs($seats['legal'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/respond", ['response' => 'decline'])
            ->assertOk();

        $seat = collect($this->duties($seats['chair'], $meeting)['seats'])->firstWhere('seat', 'legal');
        $this->assertSame('declined', $seat['state']);
    }

    public function test_a_vacant_seat_is_reported_as_vacant(): void
    {
        [$meeting, $seats] = $this->meeting(['status' => 'scheduled']);
        CommitteeMember::query()
            ->where('committee_id', $meeting->committee_id)
            ->where('seat', 'ministry_delegate')
            ->delete();

        $seat = collect($this->duties($seats['chair'], $meeting)['seats'])->firstWhere('seat', 'ministry_delegate');
        $this->assertSame('vacant', $seat['state']);
        $this->assertNull($seat['user']);
    }

    /** @return array{0: Meeting, 1: array<string, User>} */
    private function meeting(array $attributes, bool $attended = true): array
    {
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $seats = $this->fillFiveSeats($committee);
        $meeting = new Meeting(['committee_id' => $committee->id, 'title' => 'اجتماع اللجنة', 'scheduled_at' => now()]);
        $meeting->forceFill($attributes)->save();
        foreach ($seats as $user) {
            $meeting->attendees()->create(['user_id' => $user->id, 'attended' => $attended, 'invitation_status' => 'pending']);
        }

        return [$meeting, $seats];
    }

    /** @return array{available: list<array<string, mixed>>, blocked: list<array<string, string>>, seats: list<array<string, mixed>>} */
    private function duties(User $actor, Meeting $meeting): array
    {
        return $this->actingAs($actor->fresh(), 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/duties")
            ->assertOk()
            ->json('data');
    }

    /** @return list<string> */
    private function availableActions(User $actor, Meeting $meeting): array
    {
        return array_column($this->duties($actor, $meeting)['available'], 'action');
    }

    private function blockedReason(User $actor, Meeting $meeting, string $action): string
    {
        $reason = collect($this->duties($actor, $meeting)['blocked'])->firstWhere('action', $action)['reason'] ?? null;
        $this->assertNotNull($reason, "{$action} was not reported as blocked");

        return $reason;
    }
}
