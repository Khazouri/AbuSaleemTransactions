<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\HoldsPaperMeetings;
use Tests\TestCase;

/**
 * Stage 25 — the `decisions` screen: the register of recorded decisions.
 * Since 2026-10-18 its rows come from the paper sitting's results.
 */
class DecisionRegisterTest extends TestCase
{
    use HoldsPaperMeetings;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_the_register_lists_a_recorded_decision_with_its_context(): void
    {
        [$rapporteur, $meeting, $agendaItem] = $this->deferredItem();

        $response = $this->actingAs($rapporteur, 'sanctum')
            ->getJson('/api/decisions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.outcome', 'defer')
            ->assertJsonPath('data.0.decided_by.name', $rapporteur->name);

        // The register is read away from the meeting, so each row has to carry
        // enough context to link back to both sides.
        $context = $response->json('data.0.context');
        $this->assertSame($meeting->id, $context['meeting']['id']);
        $this->assertSame('لجنة شؤون الموظفين', $context['committee']['name_ar']);
        $this->assertSame($agendaItem->request_id, $context['request']['id']);
    }

    public function test_register_filters_narrow_the_population(): void
    {
        [$member, , $agendaItem] = $this->deferredItem();

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

    public function test_filters_endpoint_offers_the_committees_and_outcomes(): void
    {
        $this->paperMeeting();

        $this->actingAs($this->userWith('R04'), 'sanctum')
            ->getJson('/api/decisions/filters')
            ->assertOk()
            // Stage 63 — appeal outcomes joined the same shared filter list.
            // The retired outcomes stay filterable for decisions recorded
            // before the paper sitting.
            ->assertJsonPath('data.outcomes', [
                'approve', 'reject', 'defer',
                'conditional_approval', 'legal_opinion', 'refer_other_body', 'no_jurisdiction',
                'appeal_accept', 'appeal_partial_accept', 'appeal_reject', 'appeal_refer', 'appeal_redo',
            ])
            ->assertJsonPath('data.formats', ['xlsx', 'pdf'])
            ->assertJsonPath('data.committees.0.name_ar', 'لجنة شؤون الموظفين');
    }

    public function test_the_register_exports_as_xlsx_and_pdf(): void
    {
        $this->deferredItem();

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
     * Reading the register and carrying it out as a file stay different
     * privileges — a committee member may do the first and not the second.
     *
     * Stage 84 — `export` now seeds the same R06/R07 tier `registers`,
     * `reports` and `audit_log` already use, so the ministry and the director
     * can export here too. That is a consistency fix, not a widening of what
     * they may see: `decisions,view` is '*', so both already read these rows
     * on screen, and register 6 already exports the same population to them.
     */
    public function test_export_requires_the_export_grant_while_viewing_does_not(): void
    {
        $member = $this->userWith('R04');

        $this->actingAs($member, 'sanctum')->getJson('/api/decisions')->assertOk();
        $this->actingAs($member, 'sanctum')->getJson('/api/decisions/export')->assertForbidden();

        $this->actingAs($this->userWith('R06'), 'sanctum')->get('/api/decisions/export')->assertOk();
        $this->actingAs($this->userWith('R07'), 'sanctum')->get('/api/decisions/export')->assertOk();
        $this->actingAs($this->admin(), 'sanctum')->get('/api/decisions/export')->assertOk();
    }

    public function test_an_unrolled_user_cannot_read_the_register(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]), 'sanctum')
            ->getJson('/api/decisions')
            ->assertForbidden();
    }

    // --- fixtures -----------------------------------------------------------

    /**
     * One request the مقرر deferred on paper. Defer names no جهة الاعتماد,
     * and the register does not care which outcome produced the row.
     *
     * @return array{0: User, 1: Meeting, 2: MeetingRequest}
     */
    private function deferredItem(): array
    {
        [$rapporteur, $meeting, [$agendaItem]] = $this->paperMeeting();
        $this->recordResults($rapporteur, $meeting, [
            $agendaItem->id => ['recommendation' => 'defer', 'note' => 'الملف غير مكتمل، يؤجل للاجتماع القادم'],
        ])->assertOk();

        return [$rapporteur, $meeting, $agendaItem];
    }

    private function admin(): User
    {
        return User::where('email', 'admin@abusaleem.test')->firstOrFail();
    }
}
