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
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SitsOnCommittee;
use Tests\TestCase;

/**
 * Decision wizard, sub-project 3 — what may be done on an appeal, as the
 * appeal wizard is offered it. Same no-drift rule: a blocked reason is the
 * endpoint's own 422 for that state.
 */
class AppealActsTest extends TestCase
{
    use RefreshDatabase;
    use SitsOnCommittee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_each_status_offers_its_own_act(): void
    {
        $verifier = $this->userWithRole('R02');
        $expected = [
            'submitted' => ['verify'],
            'formal_verification' => ['jurisdiction_test'],
            'file_assembly' => ['legal_review'],
            'rejected' => ['close'],
            'outside_jurisdiction' => ['close'],
            'notified_closed' => ['reopen'],
        ];

        foreach ($expected as $status => $actions) {
            $appeal = $this->appealIn($status, $this->userWithRole('R01'));
            $this->assertSame($actions, array_column($this->acts($verifier, $appeal)['available'], 'action'), $status);
        }
    }

    public function test_execution_waits_for_the_committee_with_the_endpoints_reason(): void
    {
        $verifier = $this->userWithRole('R02');
        $appeal = $this->appealIn('committee_presentation', $this->userWithRole('R01'));
        $reason = 'لا يوجد قرار مسجل لهذا التظلم بعد.';

        $this->assertSame([['action' => 'execute_outcome', 'reason' => $reason]], $this->acts($verifier, $appeal)['blocked']);
        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/execute-outcome")
            ->assertStatus(422)
            ->assertJsonPath('message', $reason);

        $this->decide($appeal, 'appeal_reject');
        $this->assertSame(['execute_outcome'], array_column($this->acts($verifier, $appeal)['available'], 'action'));
    }

    public function test_the_appellant_is_offered_nothing_on_their_own_appeal(): void
    {
        $verifier = $this->userWithRole('R02');
        $own = $this->appealIn('submitted', $verifier);

        $this->assertSame(['available' => [], 'blocked' => []], array_intersect_key($this->acts($verifier, $own), ['available' => 1, 'blocked' => 1]));
        $this->actingAs($verifier, 'sanctum')
            ->postJson("/api/appeals/{$own->id}/verify", ['appellant_standing' => true, 'valid_target_decision' => true, 'non_duplication' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يجوز للمتظلم التحقق من تظلمه بنفسه.');
    }

    public function test_the_endpoints_status_refusals_are_unchanged(): void
    {
        $appeal = $this->appealIn('formal_verification', $this->userWithRole('R01'));

        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->postJson("/api/appeals/{$appeal->id}/verify", ['appellant_standing' => true, 'valid_target_decision' => true, 'non_duplication' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن إجراء التحقق الشكلي إلا لتظلم في حالة تقديم التظلم.');
    }

    public function test_a_seated_chair_can_open_and_nominate_an_appeal_ready_for_the_committee(): void
    {
        $chair = $this->userWithRole('R03');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $this->seatOn($committee, $chair);
        $meeting = Meeting::create(['committee_id' => $committee->id, 'title' => 'اجتماع', 'scheduled_at' => now()->addWeek()]);
        $appeal = $this->appealIn('legal_review', $this->userWithRole('R01'));

        // The nominator is disclosed only what GET meetings/appeal-options
        // already lists (plus the status) — never the appeal itself.
        $acts = $this->acts($chair, $appeal);
        $this->assertSame([['action' => 'nominate']], $acts['available']);
        $this->assertSame('legal_review', $acts['appeal']['status']['code']);
        $this->assertSame($appeal->original_request_id, $acts['appeal']['original_request']['id']);
        $this->assertArrayNotHasKey('appeal_reasons', $acts['appeal']);
        $this->assertNotContains(
            $appeal->id,
            array_column($this->actingAs($chair->fresh(), 'sanctum')->getJson('/api/appeals')->assertOk()->json('data'), 'id'),
        );
        $this->actingAs($chair->fresh(), 'sanctum')->getJson("/api/appeals/{$appeal->id}/file")->assertNotFound();

        $this->actingAs($chair->fresh(), 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['item_type' => 'appeal', 'appeal_id' => $appeal->id])
            ->assertCreated();
        // Once nominated there is nothing left for the nominator to do on it.
        $this->actingAs($chair->fresh(), 'sanctum')->getJson("/api/appeals/{$appeal->id}/acts")->assertNotFound();

        // An unseated chair holds no agenda view (the membership gate), so
        // cannot nominate — nor open an appeal that is not theirs.
        $this->actingAs($this->userWithRole('R03'), 'sanctum')
            ->getJson("/api/appeals/{$this->appealIn('legal_review', $this->userWithRole('R01'))->id}/acts")
            ->assertNotFound();
    }

    public function test_the_list_marks_the_rows_with_something_to_do(): void
    {
        $verifier = $this->userWithRole('R02');
        $theirs = $this->appealIn('submitted', $this->userWithRole('R01'));
        $own = $this->appealIn('submitted', $verifier);

        $rows = collect($this->actingAs($verifier, 'sanctum')->getJson('/api/appeals')->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($rows[$theirs->id]['has_acts']);
        $this->assertFalse($rows[$own->id]['has_acts']);
    }

    // --- helpers ------------------------------------------------------------

    /** @return array<string, mixed> */
    private function acts(User $actor, Appeal $appeal): array
    {
        return $this->actingAs($actor->fresh(), 'sanctum')
            ->getJson("/api/appeals/{$appeal->id}/acts")
            ->assertOk()
            ->json('data');
    }

    private function decide(Appeal $appeal, string $outcome): void
    {
        $meeting = Meeting::create([
            'committee_id' => Committee::create(['name_ar' => 'لجنة'])->id,
            'title' => 'اجتماع', 'status' => 'completed', 'scheduled_at' => now()->subWeek(),
        ]);
        $item = MeetingRequest::create([
            'meeting_id' => $meeting->id, 'item_type' => 'appeal', 'appeal_id' => $appeal->id, 'agenda_order' => 1,
        ]);
        Decision::create(['meeting_request_id' => $item->id, 'outcome' => $outcome, 'comment' => 'سبب', 'decided_at' => now()]);
    }

    private function appealIn(string $statusCode, User $appellant): Appeal
    {
        $original = Request::create([
            'reference_number' => 'PM-COM/2026/'.fake()->unique()->numerify('####'),
            'title' => 'طلب '.fake()->unique()->numerify('###'),
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'final_approved')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'final_approval_archiving')->value('id'),
            'created_by_user_id' => $appellant->id,
            'submitted_at' => now()->subMonth(),
        ]);

        return Appeal::create([
            'appellant_user_id' => $appellant->id,
            'original_request_id' => $original->id,
            'original_decision_reference' => 'قرار',
            'known_at' => now()->subDay(),
            'appeal_reasons' => 'أسباب',
            'final_request' => 'طلب',
            'appeal_status_id' => AppealStatus::where('code', $statusCode)->value('id'),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'department_id' => Department::where('code', 'ADM')->value('id'),
        ]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
