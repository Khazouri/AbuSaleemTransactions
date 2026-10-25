<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\Approval;
use App\Models\Decision;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\ActionRequiredNotification;
use App\Notifications\RequestNoticeNotification;
use App\Notifications\RequestStageChangedNotification;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\HoldsPaperMeetings;
use Tests\TestCase;

/**
 * Paper-meeting plan, item 3 (user decision 2026-10-25): the مقرر may take the
 * whole meeting's results back, and enter them again, until جهة الاعتماد has
 * acted on any of its files.
 */
class MeetingResultsRollbackTest extends TestCase
{
    use HoldsPaperMeetings;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_the_rapporteur_rolls_the_whole_result_back_and_enters_it_again(): void
    {
        [$rapporteur, $meeting, [$approved, $declined, $deferred]] = $this->paperMeeting(3);
        $appeal = $this->legalReviewAppeal();
        $appealItem = $this->appealOnAgenda($meeting, $appeal);

        $results = [
            $approved->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
            $declined->id => ['recommendation' => 'decline', 'approving_authority' => 'minister', 'note' => 'سبب'],
            $deferred->id => ['recommendation' => 'defer', 'note' => 'لحين الاستكمال'],
            $appealItem->id => ['recommendation' => 'approve', 'note' => 'يقبل التظلم'],
        ];
        $this->recordResults($rapporteur, $meeting, $results)->assertOk();
        $this->assertSame(1, Approval::where('request_id', $approved->request_id)->where('level', 2)->count());
        Storage::disk('local')->assertExists("meeting-minutes/{$meeting->id}");

        $this->rollback($rapporteur, $meeting)->assertOk();

        $meeting->refresh();
        $this->assertSame('scheduled', $meeting->status);
        $this->assertNull($meeting->results_recorded_at);
        $this->assertNull($meeting->results_recorded_by_user_id);
        $this->assertNull($meeting->minutes_pages);
        Storage::disk('local')->assertMissing("meeting-minutes/{$meeting->id}");
        $this->assertSame(0, Decision::count());
        $this->assertSame(['presented'], $meeting->agendaItems()->pluck('item_state')->unique()->values()->all());

        foreach ([$approved, $declined, $deferred] as $item) {
            $file = $item->request->fresh();
            $this->assertSame('receive_from_committee', $file->currentStage->code);
            $this->assertSame('in_meeting', $file->status->code);
            $this->assertNull($file->approving_authority);
            $this->assertSame('rollback_results', $file->stageLogs()->latest('id')->value('action'));
        }
        $this->assertSame(0, Approval::where('request_id', $approved->request_id)->where('level', 2)->count());
        $this->assertSame('legal_review', $appeal->fresh()->status->code);

        // The files are this meeting's agenda again, not the pending list.
        $this->actingAs($rapporteur, 'sanctum')->getJson('/api/committee-candidates')
            ->assertOk()->assertJsonMissing(['id' => $deferred->request_id]);

        $this->recordResults($rapporteur, $meeting, $results)->assertOk();
        $this->assertSame(4, Decision::count());
        $this->assertSame('completed', $meeting->fresh()->status);
    }

    public function test_refused_once_the_authority_acts_on_any_file(): void
    {
        foreach ([0, 1] as $decidedIndex) {
            [$rapporteur, $meeting, $items] = $this->paperMeeting(2);
            $this->recordResults($rapporteur, $meeting, [
                $items[0]->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
                $items[1]->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
            ])->assertOk();

            app(WorkflowService::class)->transition($items[$decidedIndex]->request->fresh(), 'approve', $this->userWith('R07'), 'يعتمد');

            $this->rollback($rapporteur, $meeting)
                ->assertStatus(422)
                ->assertJsonPath('message', 'لا يجوز التراجع بعد أن تحرك أحد الطلبات (قرار جهة الاعتماد أو غيره).');
            $this->assertSame('completed', $meeting->fresh()->status);
            $this->assertSame(2, $meeting->agendaItems()->whereHas('decision')->count());
        }
    }

    public function test_refused_for_the_chair_and_for_a_meeting_without_results(): void
    {
        [$rapporteur, $meeting, [$item]] = $this->paperMeeting();
        $this->rollback($rapporteur, $meeting)->assertStatus(422);

        $this->recordResults($rapporteur, $meeting, [
            $item->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
        ])->assertOk();

        $chair = $meeting->committee->members()->where('seat', 'chair')->first()->user;
        $this->rollback($chair, $meeting)->assertStatus(422);
        $this->assertSame('completed', $meeting->fresh()->status);
    }

    public function test_refused_when_a_deferred_file_sits_on_a_later_agenda(): void
    {
        [$rapporteur, $meeting, [$item]] = $this->paperMeeting();
        $this->recordResults($rapporteur, $meeting, [
            $item->id => ['recommendation' => 'defer', 'note' => 'لحين الاستكمال'],
        ])->assertOk();

        $later = Meeting::create([
            'committee_id' => $meeting->committee_id,
            'title' => 'الاجتماع التالي',
            'scheduled_at' => now()->addMonth(),
            'status' => 'scheduled',
            'created_by_user_id' => $rapporteur->id,
        ]);
        $later->agendaItems()->create(['request_id' => $item->request_id, 'agenda_order' => 1]);

        $this->rollback($rapporteur, $meeting)->assertStatus(422);
        $this->assertSame('deferred', $item->request->fresh()->status->code);
    }

    public function test_the_filer_hears_nothing_of_a_rollback(): void
    {
        [$rapporteur, $meeting, [$approved, $deferred]] = $this->paperMeeting(2);
        $this->recordResults($rapporteur, $meeting, [
            $approved->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
            $deferred->id => ['recommendation' => 'defer', 'note' => 'لحين الاستكمال'],
        ])->assertOk();

        Notification::fake();
        $this->rollback($rapporteur, $meeting)->assertOk();

        foreach ([$approved, $deferred] as $item) {
            $filer = $item->request->subject;
            Notification::assertNotSentTo($filer, RequestNoticeNotification::class);
            Notification::assertNotSentTo($filer, RequestStageChangedNotification::class);
            Notification::assertNotSentTo($filer, ActionRequiredNotification::class);
        }
    }

    public function test_the_wizard_offers_the_rollback_and_shows_why_it_is_held(): void
    {
        [$rapporteur, $meeting, [$item]] = $this->paperMeeting();
        $this->recordResults($rapporteur, $meeting, [
            $item->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
        ])->assertOk();

        $duties = $this->duties($rapporteur, $meeting);
        $this->assertContains('rollback_results', collect($duties['available'])->pluck('action'));

        $chair = $meeting->committee->members()->where('seat', 'chair')->first()->user;
        $this->assertNotContains('rollback_results', collect($this->duties($chair, $meeting)['available'])->pluck('action'));

        app(WorkflowService::class)->transition($item->request->fresh(), 'approve', $this->userWith('R07'), 'يعتمد');

        $blocked = collect($this->duties($rapporteur, $meeting)['blocked'])->firstWhere('action', 'rollback_results');
        $this->assertSame('لا يجوز التراجع بعد أن تحرك أحد الطلبات (قرار جهة الاعتماد أو غيره).', $blocked['reason']);
    }

    private function rollback(User $actor, Meeting $meeting): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')->deleteJson("/api/meetings/{$meeting->id}/results");
    }

    /** @return array<string, mixed> */
    private function duties(User $actor, Meeting $meeting): array
    {
        return $this->actingAs($actor, 'sanctum')->getJson("/api/meetings/{$meeting->id}/duties")->assertOk()->json('data');
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
}
