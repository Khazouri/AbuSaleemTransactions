<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AppealStatus;
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
use App\Services\DecisionEligibility;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\RunsStudySequence;
use Tests\SitsOnCommittee;
use Tests\TestCase;

/**
 * Decision wizard, sub-project 2 — what a member may do on one agenda item.
 * Each "blocked" reason must be the very message the endpoint refuses with.
 */
class AgendaItemDutiesTest extends TestCase
{
    use RefreshDatabase;
    use RunsStudySequence;
    use SitsOnCommittee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_vote_before_convening_is_blocked_with_the_endpoints_own_refusal(): void
    {
        [$meeting, $seats, $item] = $this->sitting(convened: false);

        $reason = $this->blockedReason($seats['legal'], $item, 'vote');
        $this->assertSame(DecisionEligibility::MEETING_NOT_CONVENED, $reason);

        $this->actingAs($seats['legal'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/votes", ['vote' => 'approve'])
            ->assertStatus(422)
            ->assertJsonPath('message', $reason);
    }

    public function test_an_eligible_member_votes_and_sees_their_answer_and_seat(): void
    {
        [$meeting, $seats, $item] = $this->sitting();

        $this->assertNull($this->availableDuty($seats['legal'], $item, 'vote')['my_vote']);

        $this->actingAs($seats['legal'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/votes", ['vote' => 'approve'])
            ->assertCreated();

        $duties = $this->duties($seats['legal'], $item);
        $this->assertSame('approve', collect($duties['available'])->firstWhere('action', 'vote')['my_vote']);
        $this->assertSame('voted', collect($duties['seats'])->firstWhere('seat', 'legal')['state']);
        $this->assertSame('not_voted', collect($duties['seats'])->firstWhere('seat', 'hr_director')['state']);
    }

    public function test_the_admin_without_a_seat_is_not_offered_the_vote(): void
    {
        [, , $item] = $this->sitting();
        $admin = $this->userWithRole('R08');

        $duties = $this->duties($admin, $item);
        $actions = [...array_column($duties['available'], 'action'), ...array_column($duties['blocked'], 'action')];
        $this->assertNotContains('vote', $actions);
    }

    public function test_recording_is_blocked_until_a_vote_leads_and_then_offers_the_outcome(): void
    {
        [$meeting, $seats, $item] = $this->sitting();

        $reason = $this->blockedReason($seats['chair'], $item, 'record_decision');
        $this->actingAs($seats['chair'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/decision", [])
            ->assertStatus(422)
            ->assertJsonPath('message', $reason);

        $this->actingAs($seats['legal'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/votes", ['vote' => 'approve'])
            ->assertCreated();

        $record = $this->availableDuty($seats['chair'], $item, 'record_decision');
        $this->assertSame('approve', $record['outcome']);
        $this->assertFalse($record['requires_comment']);
    }

    public function test_an_appeal_items_result_requires_a_reason(): void
    {
        [$meeting, $seats] = $this->sitting();
        $appeal = Appeal::create([
            'appellant_user_id' => $this->userWithRole('R01')->id,
            'original_request_id' => $this->file()->id,
            'original_decision_reference' => 'قرار',
            'known_at' => now()->subDay(),
            'appeal_reasons' => 'أسباب',
            'final_request' => 'طلب',
            'appeal_status_id' => AppealStatus::where('code', 'legal_review')->value('id'),
        ]);
        $item = $this->completeStudySequence($meeting->agendaItems()->create([
            'item_type' => 'appeal', 'appeal_id' => $appeal->id, 'agenda_order' => 2,
        ]));
        $this->actingAs($seats['legal'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/votes", ['vote' => 'appeal_accept'])
            ->assertCreated();

        $this->assertTrue($this->availableDuty($seats['chair'], $item, 'record_decision')['requires_comment']);
    }

    public function test_a_decided_item_offers_nothing(): void
    {
        [, $seats, $item] = $this->sitting();
        Decision::create(['meeting_request_id' => $item->id, 'outcome' => 'approve', 'decided_at' => now()]);

        $duties = $this->duties($seats['chair'], $item);
        $this->assertSame([], $duties['available']);
        $this->assertSame([], $duties['blocked']);
    }

    /** @return array{0: Meeting, 1: array<string, User>, 2: MeetingRequest} */
    private function sitting(bool $convened = true): array
    {
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $seats = $this->fillFiveSeats($committee);
        $meeting = new Meeting(['committee_id' => $committee->id, 'title' => 'اجتماع اللجنة', 'scheduled_at' => now()]);
        $meeting->forceFill([
            'status' => 'scheduled',
            'agenda_adopted_at' => now(),
            'convened_at' => $convened ? now() : null,
        ])->save();
        foreach ($seats as $user) {
            $meeting->attendees()->create(['user_id' => $user->id, 'attended' => true]);
        }
        $item = $this->completeStudySequence($meeting->agendaItems()->create([
            'item_type' => 'employee_request', 'request_id' => $this->file()->id, 'agenda_order' => 1,
        ]));

        // Fixture adaptation: completeStudySequence() auto-convenes a meeting
        // whose convened_at is still null (see RunsStudySequence's own
        // docblock) — needed by every other scenario here, but it silently
        // defeats sitting(convened: false)'s own premise. Put it back with a
        // query-builder update: Eloquent's own dirty check would otherwise
        // see no change from this in-memory instance's point of view and
        // skip the UPDATE entirely, since it never saw the trait's write.
        if (! $convened) {
            Meeting::whereKey($meeting->id)->update(['convened_at' => null]);
        }

        return [$meeting, $seats, $item];
    }

    private function file(): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب ترقية',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $this->userWithRole('R01')->id,
            'submitted_at' => now(),
        ]);
    }

    /** @return array{available: list<array<string, mixed>>, blocked: list<array<string, string>>, seats: list<array<string, mixed>>} */
    private function duties(User $actor, MeetingRequest $item): array
    {
        return $this->actingAs($actor->fresh(), 'sanctum')
            ->getJson("/api/meetings/{$item->meeting_id}/agenda/{$item->id}/duties")
            ->assertOk()
            ->json('data');
    }

    /** @return array<string, mixed> */
    private function availableDuty(User $actor, MeetingRequest $item, string $action): array
    {
        $duty = collect($this->duties($actor, $item)['available'])->firstWhere('action', $action);
        $this->assertNotNull($duty, "{$action} was not offered");

        return $duty;
    }

    private function blockedReason(User $actor, MeetingRequest $item, string $action): string
    {
        $reason = collect($this->duties($actor, $item)['blocked'])->firstWhere('action', $action)['reason'] ?? null;
        $this->assertNotNull($reason, "{$action} was not reported as blocked");

        return $reason;
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
