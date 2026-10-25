<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\Committee;
use App\Models\Decision;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        $this->assertCount(1, $meeting->minutes_pages);
        Storage::disk('local')->assertExists($meeting->minutes_pages[0]);

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

    public function test_the_minutes_pages_are_kept_in_the_rapporteurs_order_and_named_after_the_meeting(): void
    {
        [$rapporteur, $meeting, [$item]] = $this->paperMeeting();

        $this->recordResults($rapporteur, $meeting, [
            $item->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
        ], pages: [
            UploadedFile::fake()->image('scan-c.png'),
            UploadedFile::fake()->image('scan-a.jpg'),
            UploadedFile::fake()->image('scan-b.png'),
        ])->assertOk();

        $id = $meeting->id;
        $pages = $meeting->fresh()->minutes_pages;
        $this->assertSame(["meeting-minutes/{$id}/{$id}-1.png", "meeting-minutes/{$id}/{$id}-2.jpg", "meeting-minutes/{$id}/{$id}-3.png"], $pages);
        foreach ($pages as $path) {
            Storage::disk('local')->assertExists($path);
        }

        $this->actingAs($rapporteur, 'sanctum')
            ->getJson("/api/meetings/{$id}")
            ->assertJsonPath('data.minutes_page_count', 3)
            ->assertJsonPath('data.has_minutes_file', true);

        $second = $this->actingAs($rapporteur, 'sanctum')->get("/api/meetings/{$id}/minutes-file?page=2")->assertOk();
        $this->assertStringContainsString("{$id}-2.jpg", $second->headers->get('Content-Disposition'));
        $this->assertSame(Storage::disk('local')->get($pages[1]), $second->streamedContent());

        $this->actingAs($rapporteur, 'sanctum')->get("/api/meetings/{$id}/minutes-file?page=4")->assertNotFound();
        $this->actingAs($rapporteur, 'sanctum')->get("/api/meetings/{$id}/minutes-file?page=0")->assertNotFound();
    }

    public function test_the_minutes_are_scanned_images_only(): void
    {
        [$rapporteur, $meeting, [$item]] = $this->paperMeeting();
        $row = [$item->id => ['recommendation' => 'approve', 'approving_authority' => 'dean']];

        $this->recordResults($rapporteur, $meeting, $row, pages: [UploadedFile::fake()->create('محضر.pdf', 120, 'application/pdf')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('minutes_pages.0');
        $this->recordResults($rapporteur, $meeting, $row, pages: [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('minutes_pages');

        $this->assertSame('scheduled', $meeting->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_refused_submit_keeps_none_of_its_pages(): void
    {
        [$rapporteur, $meeting, [$first, $second]] = $this->paperMeeting(2);
        // Moved off the committee stage, so the transaction refuses it after
        // the pages are already stored.
        $second->request->update(['current_stage_id' => WorkflowStage::where('code', 'receive_and_register')->value('id')]);
        $approve = ['recommendation' => 'approve', 'approving_authority' => 'dean'];

        $this->recordResults($rapporteur, $meeting, [$first->id => $approve, $second->id => $approve], pages: [
            UploadedFile::fake()->image('a.png'),
            UploadedFile::fake()->image('b.png'),
        ])->assertStatus(422);

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertNull($meeting->fresh()->minutes_pages);
        $this->assertStage($first, 'receive_from_committee', 'in_meeting');
    }

    public function test_only_a_member_opens_the_minutes(): void
    {
        [$rapporteur, $meeting, [$item]] = $this->paperMeeting();
        $this->recordResults($rapporteur, $meeting, [
            $item->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
        ])->assertOk();

        // Seated, so the meetings screen is open to them, but on another committee.
        $outsider = $this->userWith('R12');
        Committee::create(['name_ar' => 'لجنة أخرى'])->members()->create(['user_id' => $outsider->id, 'seat' => 'hr_director']);

        $this->actingAs($outsider, 'sanctum')->get("/api/meetings/{$meeting->id}/minutes-file?page=1")->assertNotFound();
    }

    public function test_a_minutes_file_entered_before_the_pages_still_opens(): void
    {
        [$rapporteur, $meeting] = $this->paperMeeting(0);
        Storage::disk('local')->put("meeting-minutes/{$meeting->id}/محضر.pdf", '%PDF-1.4');
        $meeting->update([
            'status' => 'completed',
            'minutes_file_path' => "meeting-minutes/{$meeting->id}/محضر.pdf",
            'minutes_file_name' => 'محضر.pdf',
            'minutes_file_mime' => 'application/pdf',
            'results_recorded_at' => now(),
            'results_recorded_by_user_id' => $rapporteur->id,
        ]);

        $this->actingAs($rapporteur, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}")
            ->assertJsonPath('data.minutes_page_count', 0)
            ->assertJsonPath('data.has_minutes_file', true);
        $this->actingAs($rapporteur, 'sanctum')->get("/api/meetings/{$meeting->id}/minutes-file")->assertOk();
    }

    public function test_the_minutes_register_lists_a_meeting_with_scanned_pages(): void
    {
        [$rapporteur, $meeting, [$item]] = $this->paperMeeting();
        $this->recordResults($rapporteur, $meeting, [
            $item->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
        ])->assertOk();

        $this->actingAs($rapporteur, 'sanctum')
            ->getJson('/api/registers/minutes')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
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
