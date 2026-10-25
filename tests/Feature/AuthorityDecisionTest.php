<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\User;
use App\Notifications\RequestNoticeCopyNotification;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\HoldsPaperMeetings;
use Tests\TestCase;

/**
 * The committee only recommends (user decision 2026-10-18): العميد decides a
 * dean file, and مكتب العميد records the minister's paper قرار on a minister
 * file, each from the meeting's own page. The result is announced, and the
 * file goes straight to HR's service-file archive, which closes it.
 */
class AuthorityDecisionTest extends TestCase
{
    use HoldsPaperMeetings;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_the_dean_approves_from_the_meeting_page(): void
    {
        [$meeting, $item] = $this->recommended('approve', 'dean');
        $dean = $this->userWith('R07');

        $this->actingAs($dean, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}")
            ->assertOk()
            ->assertJsonPath('data.agenda_items.0.id', $item->id);

        $available = collect($this->actingAs($dean, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/authority-duties")
            ->assertOk()->json('data.available'))->pluck('action');
        $this->assertContains('authority_decision', $available);

        // «المهام المعلقة» opens the decision on the meeting's page.
        $task = collect($this->actingAs($dean, 'sanctum')->getJson('/api/my-tasks')->assertOk()->json('data.sources'))
            ->firstWhere('code', 'approval')['tasks'][0];
        $this->assertSame('meeting_details', $task['route']['name']);
        $this->assertSame(['item' => $item->id, 'decide' => 1], $task['route']['query']);

        $this->decide($dean, $meeting, $item, ['decision' => 'approve', 'note' => 'يعتمد'])->assertOk();

        $requestRecord = $item->request->fresh();
        $this->assertSame('final_approval_archiving', $requestRecord->currentStage->code);
        $this->assertSame('final_approved', $requestRecord->status->code);
    }

    public function test_the_dean_declines_and_the_file_is_not_approved(): void
    {
        [$meeting, $item] = $this->recommended('decline', 'dean');

        $this->decide($this->userWith('R07'), $meeting, $item, ['decision' => 'reject', 'note' => 'لا يعتمد'])->assertOk();

        $requestRecord = $item->request->fresh();
        $this->assertSame('final_approval_archiving', $requestRecord->currentStage->code);
        $this->assertSame('not_approved', $requestRecord->status->code);
    }

    public function test_the_ministers_office_must_attach_the_paper_decision(): void
    {
        [$meeting, $item] = $this->recommended('approve', 'minister');
        $office = $this->userWith('R06');

        $this->decide($office, $meeting, $item, ['decision' => 'approve'])->assertStatus(422);
        $this->assertSame('local_governance_ministry', $item->request->fresh()->currentStage->code);

        $this->decide($office, $meeting, $item, [
            'decision' => 'approve',
            'note' => 'وافق الوزير',
            'decision_file' => UploadedFile::fake()->create('قرار.pdf', 80, 'application/pdf'),
        ])->assertOk();

        $this->assertSame('final_approved', $item->request->fresh()->status->code);
        $attachment = Attachment::where('request_id', $item->request_id)->sole();
        $this->assertSame('approval', $attachment->file_section);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_the_other_authority_and_the_request_page_cannot_decide(): void
    {
        [$meeting, $item] = $this->recommended('approve', 'dean');

        // Only العميد decides a dean file; مكتب العميد cannot even open it.
        $this->decide($this->userWith('R06'), $meeting, $item, ['decision' => 'approve'])->assertNotFound();

        // The request page no longer takes the authority's decision.
        $this->actingAs($this->userWith('R07'), 'sanctum')
            ->postJson("/api/requests/{$item->request_id}/transition", ['action' => 'approve'])
            ->assertStatus(422);

        $this->assertSame('approval_by_authority', $item->request->fresh()->currentStage->code);
    }

    /** The request page links the authority to the meeting it decides on. */
    public function test_the_committee_summary_names_the_meeting_and_its_item(): void
    {
        [$meeting, $item] = $this->recommended('approve', 'dean');

        $this->actingAs($this->userWith('R07'), 'sanctum')
            ->getJson("/api/requests/{$item->request_id}")
            ->assertOk()
            ->assertJsonPath('data.committee_summary.meeting_id', $meeting->id)
            ->assertJsonPath('data.committee_summary.agenda_item_id', $item->id)
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

            $file->refresh();
            $this->assertSame('final_approval_archiving', $file->currentStage->code);
            $this->assertSame('final_approved', $file->status->code);
        }
    }

    /** Art. 94 — a محضر the authority sent back is re-processed before it decides. */
    public function test_an_open_return_blocks_the_authoritys_decision(): void
    {
        [$meeting, $item] = $this->recommended('approve', 'dean');
        $this->actingAs($meeting->committee->seatRapporteur(), 'sanctum')
            ->patchJson("/api/requests/{$item->request_id}/approval-return", [
                'return_kind' => 'formal',
                'return_reason_code' => 'missing_signature',
                'return_note' => 'المحضر غير موقع من أحد الأعضاء الحاضرين.',
                'letter_number' => 'ك/2026/77',
                'received_at' => now()->toDateString(),
            ])
            ->assertOk();

        $this->decide($this->userWith('R07'), $meeting, $item, ['decision' => 'approve'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يعتمد المحضر المعاد من جهة الاعتماد قبل إثبات إجراء إعادة المعالجة.');
    }

    public function test_the_authority_sees_only_meetings_whose_results_are_recorded(): void
    {
        [, $meeting] = $this->paperMeeting();

        $this->actingAs($this->userWith('R07'), 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}")
            ->assertNotFound();
    }

    public function test_the_rapporteur_hr_and_the_manager_hear_the_result(): void
    {
        [$meeting, $item] = $this->recommended('approve', 'dean');
        $rapporteur = $meeting->committee->seatRapporteur();
        $hr = $this->userWith('R12');

        Notification::fake();
        $this->decide($this->userWith('R07'), $meeting, $item, ['decision' => 'approve'])->assertOk();

        Notification::assertSentTo($rapporteur, RequestNoticeCopyNotification::class);
        Notification::assertSentTo($hr, RequestNoticeCopyNotification::class);
    }

    /** 2026-10-25 — HR alone archives (user decision); the مقرر's committee-file archive is gone. */
    public function test_hrs_service_archive_alone_closes_the_file(): void
    {
        [$meeting, $item] = $this->recommended('approve', 'dean');
        $this->decide($this->userWith('R07'), $meeting, $item, ['decision' => 'approve'])->assertOk();

        $this->actingAs($meeting->committee->seatRapporteur(), 'sanctum')
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

    /** @return array{0: Meeting, 1: MeetingRequest} */
    private function recommended(string $recommendation, string $authority): array
    {
        [$rapporteur, $meeting, [$item]] = $this->paperMeeting();

        $this->recordResults($rapporteur, $meeting, [
            $item->id => ['recommendation' => $recommendation, 'approving_authority' => $authority, 'note' => 'توصية اللجنة'],
        ])->assertOk();

        return [$meeting->fresh(), $item->fresh()];
    }

    /** @param  array<string, mixed>  $payload */
    private function decide(User $actor, Meeting $meeting, MeetingRequest $item, array $payload)
    {
        return $this->actingAs($actor, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/agenda/{$item->id}/authority-decision", $payload, ['Accept' => 'application/json']);
    }
}
