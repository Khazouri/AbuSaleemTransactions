<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SitsOnCommittee;
use Tests\TestCase;

/**
 * Decision wizard, sub-project 2 — what a member may do on a meeting. Each
 * "blocked" reason must be the very message the endpoint refuses with.
 */
class MeetingDutiesTest extends TestCase
{
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

    /** F13 (final-review) — the membership gate, not just the screen grant. */
    public function test_a_member_of_a_different_committee_is_refused_the_meetings_duties(): void
    {
        [$meeting] = $this->meeting(['status' => 'scheduled']);
        $otherCommittee = Committee::create(['name_ar' => 'لجنة أخرى']);
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->roles()->attach(Role::where('code', 'R04')->value('id'));
        $this->seatOn($otherCommittee, $outsider);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/duties")
            ->assertStatus(404);
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
    private function meeting(array $attributes): array
    {
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $seats = $this->fillFiveSeats($committee);
        $meeting = new Meeting(['committee_id' => $committee->id, 'title' => 'اجتماع اللجنة', 'scheduled_at' => now()]);
        $meeting->forceFill($attributes)->save();
        foreach ($seats as $user) {
            $meeting->attendees()->create(['user_id' => $user->id, 'invitation_status' => 'pending']);
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
