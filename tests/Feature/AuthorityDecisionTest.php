<?php

namespace Tests\Feature;

use App\Models\AuthorityDecision;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ActionRequiredNotification;
use App\Notifications\RequestNoticeCopyNotification;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\HoldsPaperMeetings;
use Tests\TestCase;

/**
 * The committee only recommends (user decision 2026-10-18): العميد decides a
 * dean file, and مكتب العميد records the minister's paper قرار on a minister
 * file, each from the meeting's own page. One قرار — its اشاري, date and
 * scanned pages — covers every file ticked in one submit, each with its own
 * approve or reject (2026-10-25). Once the مقرر confirms it the result is
 * announced, and the file goes to HR's service-file archive, which closes it.
 */
class AuthorityDecisionTest extends TestCase
{
    use HoldsPaperMeetings;
    use RefreshDatabase;

    private User $dean;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $this->dean = $this->userWith('R07');
    }

    public function test_the_dean_decides_two_files_with_one_decision(): void
    {
        [$meeting, [$first, $second]] = $this->recommended(['approve', 'decline'], 'dean');

        $this->actingAs($this->dean, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/authority-duties")
            ->assertOk()
            ->assertJsonPath('items', [['id' => $first->id, 'reason' => null], ['id' => $second->id, 'reason' => null]]);

        // «المهام المعلقة» opens the decision on the meeting's page.
        $task = collect($this->actingAs($this->dean, 'sanctum')->getJson('/api/my-tasks')->assertOk()->json('data.sources'))
            ->firstWhere('code', 'approval')['tasks'][0];
        $this->assertSame('meeting_details', $task['route']['name']);
        $this->assertSame(['item' => $first->id, 'decide' => 1], $task['route']['query']);

        $this->decide($this->dean, $meeting, [
            $first->id => ['decision' => 'approve', 'note' => 'يعتمد'],
            $second->id => ['decision' => 'reject', 'note' => 'لا يعتمد'],
        ], pages: [UploadedFile::fake()->image('ب.png'), UploadedFile::fake()->image('أ.jpg')])->assertOk();

        $decision = AuthorityDecision::sole();
        $this->assertSame('ق/2026/15', $decision->reference);
        $this->assertSame($this->dean->id, $decision->decided_by_user_id);
        // Named «<id>-<n>» in the order the pages were sent, as the محضر is.
        $this->assertSame([
            "authority-decisions/{$decision->id}/{$decision->id}-1.png",
            "authority-decisions/{$decision->id}/{$decision->id}-2.jpg",
        ], $decision->pages);
        foreach ($decision->pages as $path) {
            Storage::disk('local')->assertExists($path);
        }
        $this->assertSame([$decision->id, $decision->id], [$first->fresh()->authority_decision_id, $second->fresh()->authority_decision_id]);

        // 2026-10-25 — the مقرر reviews each before it is final (RapporteurDecisionReviewTest).
        $this->assertSame(['rapporteur_decision_review', 'authority_approved'], $this->stageAndStatus($first));
        $this->assertSame(['rapporteur_decision_review', 'authority_rejected'], $this->stageAndStatus($second));
    }

    public function test_the_ministers_office_records_the_ministers_decision(): void
    {
        [$meeting, [$item]] = $this->recommended(['approve'], 'minister');

        $this->decide($this->userWith('R06'), $meeting, [$item->id => ['decision' => 'approve', 'note' => 'وافق الوزير']])->assertOk();

        $this->assertSame(['rapporteur_decision_review', 'authority_approved'], $this->stageAndStatus($item));
        $this->assertNotNull($item->fresh()->authority_decision_id);
    }

    public function test_a_malformed_decision_is_refused(): void
    {
        [$meeting, [$item]] = $this->recommended(['approve'], 'dean');

        $this->decide($this->dean, $meeting, [$item->id => ['decision' => 'reject']])
            ->assertStatus(422)->assertJsonValidationErrors("items.{$item->id}.note");
        $this->decide($this->dean, $meeting, [$item->id => ['decision' => 'approve']], pages: [])
            ->assertStatus(422)->assertJsonValidationErrors('pages');
        $this->decide($this->dean, $meeting, [$item->id => ['decision' => 'approve']], pages: [UploadedFile::fake()->create('قرار.pdf', 80, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('pages.0');
        $this->decide($this->dean, $meeting, [$item->id => ['decision' => 'approve']], decidedOn: now()->addDay()->toDateString())
            ->assertStatus(422)->assertJsonValidationErrors('decided_on');
        $this->decide($this->dean, $meeting, [$item->id => ['decision' => 'approve']], reference: '')
            ->assertStatus(422)->assertJsonValidationErrors('reference');

        $this->assertSame('approval_by_authority', $item->request->fresh()->currentStage->code);
        $this->assertSame(0, AuthorityDecision::count());
    }

    /** Art. 94 — one blocked file holds the whole submit back, and says which file it is. */
    public function test_one_blocked_file_moves_nothing(): void
    {
        [$meeting, [$first, $second]] = $this->recommended(['approve', 'approve'], 'dean');
        $this->actingAs($meeting->committee->seatRapporteur(), 'sanctum')
            ->patchJson("/api/requests/{$second->request_id}/approval-return", [
                'return_kind' => 'formal',
                'return_reason_code' => 'missing_signature',
                'return_note' => 'المحضر غير موقع من أحد الأعضاء الحاضرين.',
                'letter_number' => 'ك/2026/77',
                'received_at' => now()->toDateString(),
            ])
            ->assertOk();

        $this->actingAs($this->dean, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/authority-duties")
            ->assertJsonPath('items.1.reason', 'لا يعتمد المحضر المعاد من جهة الاعتماد قبل إثبات إجراء إعادة المعالجة.');

        $this->decide($this->dean, $meeting, [
            $first->id => ['decision' => 'approve'],
            $second->id => ['decision' => 'approve'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', $second->request->reference_number.': لا يعتمد المحضر المعاد من جهة الاعتماد قبل إثبات إجراء إعادة المعالجة.');

        $this->assertSame('approval_by_authority', $first->request->fresh()->currentStage->code);
        $this->assertSame(0, AuthorityDecision::count());
        $this->assertSame([], Storage::disk('local')->allFiles('authority-decisions'));
    }

    public function test_items_not_awaiting_this_authority_are_refused(): void
    {
        [$rapporteur, $meeting, [$deferred, $approved]] = $this->paperMeeting(2);
        $this->recordResults($rapporteur, $meeting, [
            $deferred->id => ['recommendation' => 'defer', 'note' => 'يؤجل'],
            $approved->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
        ])->assertOk();
        [, [$elsewhere]] = $this->recommended(['approve'], 'dean');

        // A deferred file is back before the committee, out of the dean's sight.
        $this->decide($this->dean, $meeting, [$deferred->id => ['decision' => 'approve']])->assertNotFound();
        // An item of another meeting is not this page's to decide.
        $this->decide($this->dean, $meeting, [$elsewhere->id => ['decision' => 'approve']])->assertNotFound();
        // Only العميد decides a dean file; مكتب العميد cannot even open it.
        $this->decide($this->userWith('R06'), $meeting, [$approved->id => ['decision' => 'approve']])->assertNotFound();
        // The request page no longer takes the authority's decision.
        $this->actingAs($this->dean, 'sanctum')
            ->postJson("/api/requests/{$approved->request_id}/transition", ['action' => 'approve'])
            ->assertStatus(422);

        $this->assertSame('approval_by_authority', $approved->request->fresh()->currentStage->code);
        $this->assertSame(0, AuthorityDecision::count());
    }

    /** A file deferred at one sitting and recommended at the next is decided from the second. */
    public function test_the_duties_leave_out_a_deferral_from_an_earlier_meeting(): void
    {
        [$rapporteur, $earlier, [$deferred]] = $this->paperMeeting();
        $this->recordResults($rapporteur, $earlier, [$deferred->id => ['recommendation' => 'defer', 'note' => 'يؤجل']])->assertOk();

        [$nextRapporteur, $later] = $this->paperMeeting(0);
        $item = $later->agendaItems()->create(['request_id' => $deferred->request_id, 'agenda_order' => 1]);
        $this->recordResults($nextRapporteur, $later, [$item->id => ['recommendation' => 'approve', 'approving_authority' => 'dean']])->assertOk();

        $this->actingAs($this->dean, 'sanctum')->getJson("/api/meetings/{$earlier->id}/authority-duties")
            ->assertOk()->assertJsonPath('items', []);
        $this->actingAs($this->dean, 'sanctum')->getJson("/api/meetings/{$later->id}/authority-duties")
            ->assertOk()->assertJsonPath('items', [['id' => $item->id, 'reason' => null]]);
    }

    public function test_the_creator_sees_the_decision_and_a_stranger_does_not(): void
    {
        [$meeting, [$item]] = $this->recommended(['approve'], 'dean');
        $creator = $item->request->createdBy;
        $creator->roles()->attach(Role::where('code', 'R01')->value('id'));
        $this->decide($this->dean, $meeting, [$item->id => ['decision' => 'approve']])->assertOk();

        $this->actingAs($creator, 'sanctum')
            ->getJson("/api/requests/{$item->request_id}")
            ->assertOk()
            ->assertJsonPath('data.committee_summary.authority_decision.reference', 'ق/2026/15')
            ->assertJsonPath('data.committee_summary.authority_decision.decided_on', now()->toDateString())
            ->assertJsonPath('data.committee_summary.authority_decision.page_count', 1);

        $this->actingAs($creator, 'sanctum')->get("/api/requests/{$item->request_id}/authority-decision/pages/1")->assertOk();
        $this->actingAs($creator, 'sanctum')->get("/api/requests/{$item->request_id}/authority-decision/pages/2")->assertNotFound();
        $this->actingAs($this->userWith('R01'), 'sanctum')->get("/api/requests/{$item->request_id}/authority-decision/pages/1")->assertNotFound();
    }

    /** The مقرر sends a decision back: it is no longer shown, and the authority decides again. */
    public function test_a_returned_file_is_decided_again(): void
    {
        [$meeting, [$item]] = $this->recommended(['approve'], 'dean');
        $this->decide($this->dean, $meeting, [$item->id => ['decision' => 'approve']])->assertOk();

        $this->actingAs($meeting->committee->seatRapporteur(), 'sanctum')
            ->postJson("/api/requests/{$item->request_id}/transition", ['action' => 'return_to_authority', 'comment' => 'المحضر لا يطابق القرار'])
            ->assertOk();

        $this->actingAs($this->dean, 'sanctum')
            ->getJson("/api/requests/{$item->request_id}")
            ->assertOk()
            ->assertJsonPath('data.committee_summary.authority_decision', null);
        $this->actingAs($this->dean, 'sanctum')->get("/api/requests/{$item->request_id}/authority-decision/pages/1")->assertNotFound();

        $this->decide($this->dean, $meeting, [$item->id => ['decision' => 'reject', 'note' => 'بعد المراجعة لا يعتمد']], reference: 'ق/2026/16')->assertOk();

        $this->assertSame(['rapporteur_decision_review', 'authority_rejected'], $this->stageAndStatus($item));
        $this->assertSame('ق/2026/16', $item->fresh()->authorityDecision->reference);
    }

    public function test_the_single_item_route_is_gone(): void
    {
        [$meeting, [$item]] = $this->recommended(['approve'], 'dean');

        $this->actingAs($this->dean, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/authority-decision", ['decision' => 'approve'])
            ->assertNotFound();
        $this->actingAs($this->dean, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/authority-duties")
            ->assertNotFound();
    }

    /** The request page links the authority to the meeting it decides on. */
    public function test_the_committee_summary_names_the_meeting_and_its_item(): void
    {
        [$meeting, [$item]] = $this->recommended(['approve'], 'dean');

        $this->actingAs($this->dean, 'sanctum')
            ->getJson("/api/requests/{$item->request_id}")
            ->assertOk()
            ->assertJsonPath('data.committee_summary.meeting_id', $meeting->id)
            ->assertJsonPath('data.committee_summary.agenda_item_id', $item->id)
            ->assertJsonPath('data.committee_summary.authority_decision', null)
            ->assertJsonPath('data.blocked_transitions.0.reason', 'يُسجَّل قرار جهة الاعتماد من صفحة الاجتماع.');
    }

    /**
     * R08's `deadline_expired` sends a file to the ministry with no توصية, so
     * no meeting page can take its decision: the request page does.
     */
    public function test_an_escalated_file_is_decided_on_the_request_page(): void
    {
        $unlisted = $this->committeeFile();
        // Deferred at a sitting, then escalated: its only decided item is a deferral.
        [$rapporteur, $meeting, [$item]] = $this->paperMeeting();
        $this->recordResults($rapporteur, $meeting, [$item->id => ['recommendation' => 'defer', 'note' => 'يؤجل']])->assertOk();
        $deferred = $item->request->fresh();

        $admin = $this->userWith('R08');
        $office = $this->userWith('R06');
        foreach ([$unlisted, $deferred] as $file) {
            $file->forceFill(['overdue_at' => now()])->save();
            app(WorkflowService::class)->transition($file->fresh(), 'deadline_expired', $admin, 'انتهت المهلة النظامية.');

            $this->actingAs($office, 'sanctum')
                ->getJson("/api/requests/{$file->id}")
                ->assertOk()
                ->assertJsonPath('data.blocked_transitions', []);

            // «المهام المعلقة» sends it to the request page, not a meeting.
            $task = collect(collect($this->actingAs($office, 'sanctum')->getJson('/api/my-tasks')->assertOk()->json('data.sources'))
                ->firstWhere('code', 'approval')['tasks'])->firstWhere('id', "approval:{$file->id}");
            $this->assertSame('request_details', $task['route']['name']);

            $this->actingAs($office, 'sanctum')
                ->postJson("/api/requests/{$file->id}/transition", ['action' => 'approve', 'comment' => 'يعتمد'])
                ->assertOk();

            // 2026-10-25 — the decision waits for the مقرر's review, as a meeting one does.
            $file->refresh();
            $this->assertSame('rapporteur_decision_review', $file->currentStage->code);
            $this->assertSame('authority_approved', $file->status->code);
        }
    }

    public function test_the_authority_sees_only_meetings_whose_results_are_recorded(): void
    {
        [, $meeting] = $this->paperMeeting();

        $this->actingAs($this->dean, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}")
            ->assertNotFound();
    }

    public function test_the_rapporteur_hr_and_the_manager_hear_the_result(): void
    {
        [$meeting, [$item]] = $this->recommended(['approve'], 'dean');
        $rapporteur = $meeting->committee->seatRapporteur();
        $hr = $this->userWith('R12');

        Notification::fake();
        $this->decide($this->dean, $meeting, [$item->id => ['decision' => 'approve']])->assertOk();

        // 2026-10-25 — the مقرر is asked to review the decision, and HR and the
        // manager hear the result once the مقرر confirms it.
        Notification::assertSentTo($rapporteur, ActionRequiredNotification::class);
        Notification::assertNotSentTo($hr, RequestNoticeCopyNotification::class);
        $this->confirm($rapporteur, $item);
        Notification::assertSentTo($hr, RequestNoticeCopyNotification::class);
    }

    /** 2026-10-25 — HR alone archives (user decision); the مقرر's committee-file archive is gone. */
    public function test_hrs_service_archive_alone_closes_the_file(): void
    {
        [$meeting, [$item]] = $this->recommended(['approve'], 'dean');
        $this->decide($this->dean, $meeting, [$item->id => ['decision' => 'approve']])->assertOk();
        $rapporteur = $meeting->committee->seatRapporteur();
        // 2026-10-25 — archived once the مقرر confirms the decision.
        $this->confirm($rapporteur, $item);

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$item->request_id}/archive/committee-file", ['location' => 'خزانة 3'])
            ->assertNotFound();

        $this->actingAs($this->userWith('R12'), 'sanctum')
            ->patchJson("/api/requests/{$item->request_id}/archive/service-file", ['location' => 'ملف الخدمة'])
            ->assertOk();

        $requestRecord = $item->request->fresh();
        $this->assertSame('completed_closed', $requestRecord->status->code);
        $this->assertNotNull($requestRecord->closed_at);
        $this->assertSame('العميد', $requestRecord->closure['approving_body']);
    }

    /**
     * One meeting whose results recommend each of its files as given.
     *
     * @param  list<string>  $recommendations
     * @return array{0: Meeting, 1: list<MeetingRequest>}
     */
    private function recommended(array $recommendations, string $authority): array
    {
        [$rapporteur, $meeting, $items] = $this->paperMeeting(count($recommendations));

        $rows = [];
        foreach ($items as $index => $item) {
            $rows[$item->id] = ['recommendation' => $recommendations[$index], 'approving_authority' => $authority, 'note' => 'توصية اللجنة'];
        }
        $this->recordResults($rapporteur, $meeting, $rows)->assertOk();

        return [$meeting->fresh(), array_map(fn (MeetingRequest $item) => $item->fresh(), $items)];
    }

    private function confirm(User $rapporteur, MeetingRequest $item): void
    {
        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$item->request_id}/transition", ['action' => 'confirm_decision'])
            ->assertOk();
    }

    /** @return array{0: string, 1: string} */
    private function stageAndStatus(MeetingRequest $item): array
    {
        $requestRecord = $item->request->fresh();

        return [$requestRecord->currentStage->code, $requestRecord->status->code];
    }

    /**
     * @param  array<int, array<string, string>>  $items  agenda item id => decision and note
     * @param  list<UploadedFile>|null  $pages
     */
    private function decide(User $actor, Meeting $meeting, array $items, ?array $pages = null, ?string $decidedOn = null, string $reference = 'ق/2026/15'): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')->post("/api/meetings/{$meeting->id}/authority-decisions", [
            'items' => $items,
            'reference' => $reference,
            'decided_on' => $decidedOn ?? now()->toDateString(),
            'pages' => $pages ?? [UploadedFile::fake()->image('قرار.jpg')],
        ], ['Accept' => 'application/json']);
    }
}
