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
use App\Services\DecisionEligibility;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\RecordsStructuredDecisions;
use Tests\TestCase;

/**
 * Stage 25 — the `decisions` screen: the register of recorded decisions and
 * the worklist of items still awaiting the caller's vote.
 */
class DecisionRegisterTest extends TestCase
{
    use RecordsStructuredDecisions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_register_lists_a_recorded_decision_with_its_tally_and_context(): void
    {
        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();
        $this->recordDeferDecision($head, $member, $meeting, $agendaItem);

        $response = $this->actingAs($member, 'sanctum')
            ->getJson('/api/decisions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.outcome', 'defer')
            ->assertJsonPath('data.0.votes_defer_count', 2)
            ->assertJsonPath('data.0.decided_by.name', $head->name);

        // The register is read away from the meeting, so each row has to carry
        // enough context to link back to both sides.
        $context = $response->json('data.0.context');
        $this->assertSame($meeting->id, $context['meeting']['id']);
        $this->assertSame('لجنة المشتريات', $context['committee']['name_ar']);
        $this->assertSame($agendaItem->request_id, $context['request']['id']);
    }

    public function test_register_filters_narrow_the_population(): void
    {
        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();
        $this->recordDeferDecision($head, $member, $meeting, $agendaItem);

        $reference = $agendaItem->request()->value('reference_number');

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/decisions?outcome=defer')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // A different outcome, a different committee and a date window that
        // closes before the decision each exclude the only row there is.
        $this->actingAs($member, 'sanctum')
            ->getJson('/api/decisions?outcome=approve')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $otherCommittee = Committee::create(['name_ar' => 'لجنة أخرى']);
        $this->actingAs($member, 'sanctum')
            ->getJson("/api/decisions?committee_id={$otherCommittee->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/decisions?date_to='.now()->subDay()->format('Y-m-d'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/decisions?search='.urlencode($reference))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * The worklist and the vote endpoint must agree. Anything the worklist
     * offers has to be votable, and anything it hides has to be refused —
     * otherwise the screen shows buttons that 422.
     */
    public function test_the_worklist_shows_only_items_the_caller_may_actually_vote_on(): void
    {
        [$head, $member, $committee, $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/decisions/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $agendaItem->id)
            ->assertJsonPath('data.0.meeting.id', $meeting->id);

        // Not a member of this committee at all.
        $outsider = $this->userWithRole('R04');
        $this->actingAs($outsider, 'sanctum')
            ->getJson('/api/decisions/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // A member who did not attend the sitting.
        $absentee = $this->userWithRole('R04');
        $committee->members()->create(['user_id' => $absentee->id]);
        $meeting->attendees()->create(['user_id' => $absentee->id, 'attended' => false]);
        $this->actingAs($absentee, 'sanctum')
            ->getJson('/api/decisions/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $eligibility = app(DecisionEligibility::class);
        $this->assertNull($eligibility->reasonBlockingVote($agendaItem, $member));
        $this->assertNotNull($eligibility->reasonBlockingVote($agendaItem, $outsider));
        $this->assertNotNull($eligibility->reasonBlockingVote($agendaItem, $absentee));

        // Once the decision is recorded the item leaves everyone's worklist.
        $this->recordDeferDecision($head, $member, $meeting, $agendaItem);
        $this->actingAs($member, 'sanctum')
            ->getJson('/api/decisions/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_filters_endpoint_offers_the_committees_and_outcomes(): void
    {
        $this->committeeMeetingWithAgendaItem();

        $this->actingAs($this->userWithRole('R04'), 'sanctum')
            ->getJson('/api/decisions/filters')
            ->assertOk()
            // Stage 63 — appeal outcomes joined the same shared filter list.
            ->assertJsonPath('data.outcomes', [
                'approve', 'reject', 'defer',
                'conditional_approval', 'legal_opinion', 'refer_other_body', 'no_jurisdiction',
                'appeal_accept', 'appeal_partial_accept', 'appeal_reject', 'appeal_refer', 'appeal_redo',
            ])
            ->assertJsonPath('data.formats', ['xlsx', 'pdf'])
            ->assertJsonPath('data.committees.0.name_ar', 'لجنة المشتريات');
    }

    public function test_the_register_exports_as_xlsx_and_pdf(): void
    {
        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();
        $this->recordDeferDecision($head, $member, $meeting, $agendaItem);

        $admin = $this->admin();

        $xlsx = $this->actingAs($admin, 'sanctum')
            ->get('/api/decisions/export?format=xlsx')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertStringContainsString('attachment;', $xlsx->headers->get('Content-Disposition'));
        $this->assertStringContainsString('decisions-register-', $xlsx->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('PK', $xlsx->getContent());

        // Arabic is the harder path — it is what forces mPDF's shaping — so it
        // is the one exercised here.
        $pdf = $this->actingAs($admin, 'sanctum')
            ->get('/api/decisions/export?format=pdf&locale=ar')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    /**
     * `decisions` seeds view/add/approve/print but not export, so exporting
     * falls to R08 only — reading the register and carrying it out as a file
     * are different privileges.
     */
    public function test_export_requires_the_export_grant_while_viewing_does_not(): void
    {
        $member = $this->userWithRole('R04');

        $this->actingAs($member, 'sanctum')->getJson('/api/decisions')->assertOk();
        $this->actingAs($member, 'sanctum')->getJson('/api/decisions/pending')->assertOk();
        $this->actingAs($member, 'sanctum')->getJson('/api/decisions/export')->assertForbidden();

        $this->actingAs($this->admin(), 'sanctum')->get('/api/decisions/export')->assertOk();
    }

    public function test_an_unrolled_user_cannot_read_the_register(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]), 'sanctum')
            ->getJson('/api/decisions')
            ->assertForbidden();
    }

    // --- fixtures -----------------------------------------------------------

    /** Two members who both attended, one request on the agenda. */
    private function committeeMeetingWithAgendaItem(): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');

        $committee = Committee::create(['name_ar' => 'لجنة المشتريات']);
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

        $agendaItem = $meeting->agendaItems()->create([
            'request_id' => $this->requestAtCommitteeStage()->id,
            'agenda_order' => 1,
        ]);

        return [$head, $member, $committee, $meeting, $agendaItem];
    }

    /**
     * Defer rather than approve: it needs no signature file, and the register
     * does not care which outcome produced the row.
     */
    private function recordDeferDecision(User $head, User $member, Meeting $meeting, MeetingRequest $agendaItem): void
    {
        foreach ([$member, $head] as $voter) {
            $this->actingAs($voter, 'sanctum')
                ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'defer'])
                ->assertCreated();
        }

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('defer', [
                'comment' => 'الملف غير مكتمل، يؤجل للاجتماع القادم',
            ]))
            ->assertCreated();
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

    private function admin(): User
    {
        return User::where('email', 'admin@abusaleem.test')->firstOrFail();
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
