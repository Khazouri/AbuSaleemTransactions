<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\Decision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\HoldsPaperMeetings;
use Tests\TestCase;

/**
 * The committee sits on paper (user decision 2026-10-18). After the meeting
 * the مقرر enters every item's توصية, its note and its جهة الاعتماد, attaches
 * the محضر, and submits once. The committee only recommends: a موافقة or a
 * عدم موافقة goes to العميد or الوزير, and a تأجيل goes back to the pending list.
 */
class PaperMeetingResultsTest extends TestCase
{
    use HoldsPaperMeetings;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_the_rapporteur_records_every_result_and_the_meeting_closes(): void
    {
        [$rapporteur, $meeting, [$dean, $minister, $declined, $deferred]] = $this->paperMeeting(4);

        $this->recordResults($rapporteur, $meeting, [
            $dean->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
            $minister->id => ['recommendation' => 'approve', 'approving_authority' => 'minister', 'note' => 'يوصى بالموافقة'],
            $declined->id => ['recommendation' => 'decline', 'approving_authority' => 'dean', 'note' => 'لا تنطبق الشروط'],
            $deferred->id => ['recommendation' => 'defer', 'note' => 'لحين استكمال كشف الخدمة'],
        ])->assertOk();

        $this->assertStage($dean, 'approval_by_authority', 'awaiting_municipal_approval');
        $this->assertStage($minister, 'local_governance_ministry', 'awaiting_central_approval');
        $this->assertStage($declined, 'approval_by_authority', 'awaiting_municipal_approval');
        $this->assertStage($deferred, 'receive_from_committee', 'deferred');

        $this->assertSame('minister', $minister->request->fresh()->approving_authority);
        $this->assertSame(['approve', 'approve', 'reject', 'defer'], Decision::query()->orderBy('meeting_request_id')->pluck('outcome')->all());
        $this->assertSame('لا تنطبق الشروط', $declined->fresh()->decision->comment);
        $this->assertNotNull($declined->fresh()->decision->decision_number);

        $meeting->refresh();
        $this->assertSame('completed', $meeting->status);
        $this->assertSame($rapporteur->id, $meeting->results_recorded_by_user_id);
        Storage::disk('local')->assertExists($meeting->minutes_file_path);

        // A deferred file is back on the pending list for a later meeting.
        $this->actingAs($rapporteur, 'sanctum')
            ->getJson('/api/committee-candidates')
            ->assertOk()
            ->assertJsonFragment(['id' => $deferred->request_id]);

        $this->actingAs($rapporteur, 'sanctum')
            ->get("/api/meetings/{$meeting->id}/minutes-file")
            ->assertOk();
    }

    public function test_a_declined_minister_file_goes_to_the_ministry(): void
    {
        [$rapporteur, $meeting, [$item]] = $this->paperMeeting();

        $this->recordResults($rapporteur, $meeting, [
            $item->id => ['recommendation' => 'decline', 'approving_authority' => 'minister', 'note' => 'سبب'],
        ])->assertOk();

        $this->assertStage($item, 'local_governance_ministry', 'awaiting_central_approval');
    }

    public function test_only_the_rapporteur_records_the_results_once_the_date_has_come(): void
    {
        [$rapporteur, $meeting, [$item]] = $this->paperMeeting();
        $row = [$item->id => ['recommendation' => 'approve', 'approving_authority' => 'dean']];

        $chair = $meeting->committee->members()->where('seat', 'chair')->first()->user;
        $this->recordResults($chair, $meeting, $row)->assertStatus(422);

        [$rapporteur, $future, [$futureItem]] = $this->paperMeeting(scheduledAt: now()->addDay());
        $this->recordResults($rapporteur, $future, [$futureItem->id => $row[$item->id]])->assertStatus(422);

        [$rapporteur, $pending, [$pendingItem]] = $this->paperMeeting(status: 'pending_confirmation');
        $this->recordResults($rapporteur, $pending, [$pendingItem->id => $row[$item->id]])->assertStatus(422);

        [$rapporteur, $empty] = $this->paperMeeting(0);
        $this->recordResults($rapporteur, $empty, [])->assertStatus(422);

        $this->assertStage($item, 'receive_from_committee', 'in_meeting');
    }

    public function test_every_item_needs_a_complete_row_and_the_minutes(): void
    {
        [$rapporteur, $meeting, [$first, $second]] = $this->paperMeeting(2);
        $approve = ['recommendation' => 'approve', 'approving_authority' => 'dean'];

        // One item left out.
        $this->recordResults($rapporteur, $meeting, [$first->id => $approve])->assertStatus(422);
        // An approval with no authority.
        $this->recordResults($rapporteur, $meeting, [
            $first->id => $approve,
            $second->id => ['recommendation' => 'approve'],
        ])->assertStatus(422);
        // A decline with no reason (Art. 91).
        $this->recordResults($rapporteur, $meeting, [
            $first->id => $approve,
            $second->id => ['recommendation' => 'decline', 'approving_authority' => 'dean'],
        ])->assertStatus(422);
        // No محضر.
        $this->recordResults($rapporteur, $meeting, [$first->id => $approve, $second->id => $approve], withMinutes: false)
            ->assertStatus(422);

        $this->assertStage($first, 'receive_from_committee', 'in_meeting');
        $this->assertSame('scheduled', $meeting->fresh()->status);
        $this->assertSame(0, Decision::count());
    }

    public function test_the_rapporteur_is_offered_the_results_and_nothing_of_the_in_system_sitting(): void
    {
        [$rapporteur, $meeting] = $this->paperMeeting();

        $available = collect($this->actingAs($rapporteur, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/duties")
            ->assertOk()
            ->json('data.available'))->pluck('action');

        $this->assertContains('record_results', $available);
        $this->assertNotContains('convene', $available);
        $this->assertNotContains('close', $available);
    }

    public function test_an_appeal_on_the_agenda_is_decided_with_the_results(): void
    {
        [$rapporteur, $meeting, [$item]] = $this->paperMeeting();
        $accepted = $this->appealOnAgenda($meeting, $this->legalReviewAppeal());
        $deferred = $this->appealOnAgenda($meeting, $this->legalReviewAppeal());

        // An appeal's outcome states the committee's reason.
        $this->recordResults($rapporteur, $meeting, [
            $item->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
            $accepted->id => ['recommendation' => 'approve'],
            $deferred->id => ['recommendation' => 'defer', 'note' => 'لحين ورود الرد'],
        ])->assertStatus(422);

        $this->recordResults($rapporteur, $meeting, [
            $item->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
            $accepted->id => ['recommendation' => 'approve', 'note' => 'ثبت وجه التظلم'],
            $deferred->id => ['recommendation' => 'defer', 'note' => 'لحين ورود الرد'],
        ])->assertOk();

        // The committee decides an appeal itself: no جهة الاعتماد.
        $this->assertSame('appeal_accept', $accepted->fresh()->decision->outcome);
        $this->assertSame('ثبت وجه التظلم', $accepted->fresh()->decision->comment);
        $this->assertSame('committee_presentation', $accepted->appeal->fresh()->status->code);

        // A deferred appeal stays ready for a later agenda.
        $appeal = $deferred->appeal->fresh();
        $this->assertSame('legal_review', $appeal->status->code);
        $this->assertTrue($appeal->awaitsNomination());
        $this->assertSame('completed', $meeting->fresh()->status);
    }

    private function legalReviewAppeal(): Appeal
    {
        $original = $this->committeeFile();

        return Appeal::create([
            'appellant_user_id' => $original->subject_user_id,
            'original_request_id' => $original->id,
            'original_decision_reference' => 'قرار',
            'known_at' => now()->subDay(),
            'appeal_reasons' => 'أسباب',
            'final_request' => 'طلب',
            'appeal_status_id' => AppealStatus::where('code', 'legal_review')->value('id'),
        ]);
    }

    private function assertStage($item, string $stage, string $status): void
    {
        $requestRecord = $item->request->fresh();
        $this->assertSame($stage, $requestRecord->currentStage->code);
        $this->assertSame($status, $requestRecord->status->code);
    }
}
