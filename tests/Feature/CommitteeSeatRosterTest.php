<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Request;
use App\Models\Role;
use App\Models\User;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\HoldsPaperMeetings;
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
    use HoldsPaperMeetings;
    use RefreshDatabase;

    /**
     * Stage 103 — seats are no longer picked per committee: each is held by
     * the one holder of its role, seated by Committee::seatRoleHolders().
     */
    public function test_a_committees_five_seats_are_individually_identifiable(): void
    {
        $this->seed(DatabaseSeeder::class);

        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $chair = $this->userWithRole('R03');
        $legal = $this->userWithRole('R11');
        $rapporteur = $this->userWithRole('R02');

        $committee->seatRoleHolders();

        $response = $this->actingAs($chair, 'sanctum')->getJson('/api/committees')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $committee->id);

        $this->assertSame($chair->id, $row['seats']['chair']['user']['id']);
        $this->assertSame($legal->id, $row['seats']['legal']['user']['id']);
        $this->assertNull($row['seats']['hr_director']);
        $this->assertNull($row['seats']['ministry_delegate']);
        $this->assertSame($rapporteur->id, $row['seats']['rapporteur']['user']['id']);
        $this->assertCount(3, $row['members']);
    }

    public function test_an_unknown_seat_value_is_rejected(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->putJson('/api/committee-roles/treasurer', ['user_id' => $this->userWithRole('R01')->id])
            ->assertNotFound();
    }

    /**
     * The load-bearing guarantee this stage adds: seating مندوب الخدمة
     * المدنية is membership only (Art. 14) — it must never itself decide
     * whether a request also needs the separate central ministry approval.
     * Both scenarios share the same kind of committee with the same
     * ministry-delegate seat and the same grade. 2026-10-17 — the only thing
     * that differs is the pick of جهة الاعتماد, which replaced the grade as
     * what decides it; since 2026-10-18 the مقرر enters that pick from the
     * paper sitting.
     */
    public function test_the_ministry_delegate_seat_never_substitutes_for_the_separate_ministry_approval(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');

        $requiresMinistry = $this->decideAndAdvanceToAuthorityCheckpoint(authority: 'minister');
        $this->assertSame('local_governance_ministry', $requiresMinistry->fresh()->currentStage->code);

        // Same committee shape, same delegate seat, same grade: the dean's
        // file never sees the ministry, whoever sat on the committee.
        $bypassesMinistry = $this->decideAndAdvanceToAuthorityCheckpoint(authority: 'dean');
        $this->assertSame('final_approval_archiving', $bypassesMinistry->fresh()->currentStage->code);
        $this->assertSame('final_approved', $bypassesMinistry->fresh()->status->code);
    }

    private function decideAndAdvanceToAuthorityCheckpoint(string $authority): Request
    {
        [$rapporteur, $meeting, [$agendaItem]] = $this->paperMeeting();
        $meeting->committee->members()->create(['user_id' => $this->userWithRole('R04')->id, 'seat' => 'ministry_delegate']);
        $requestRecord = $agendaItem->request;
        $requestRecord->update(['decision_grade' => 10]);

        $this->recordResults($rapporteur, $meeting, [
            $agendaItem->id => ['recommendation' => 'approve', 'approving_authority' => $authority],
        ])->assertOk();

        // The dean decides from the meeting page (AuthorityDecisionTest); the
        // workflow move is what is tested here.
        if ($authority === 'dean') {
            app(WorkflowService::class)->transition($requestRecord->fresh(), 'approve', $this->userWithRole('R07'));
        }

        return $requestRecord;
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
