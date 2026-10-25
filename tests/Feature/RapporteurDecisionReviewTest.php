<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\User;
use App\Models\Workflow;
use App\Notifications\ActionRequiredNotification;
use App\Notifications\RequestNoticeCopyNotification;
use App\Notifications\RequestNoticeNotification;
use App\Services\Lifecycle\RequestResponsibilityService;
use App\Services\WorkflowService;
use App\Services\WorkflowVersionValidator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\HoldsPaperMeetings;
use Tests\TestCase;

/**
 * Plan item 7 (2026-10-25): جهة الاعتماد's approve or reject goes back to the
 * مقرر, who confirms it (the file's final result) or returns it to the same
 * authority with a note. The employee hears only the confirmed result.
 *
 * The authority's own decision is taken through WorkflowService::transition(),
 * the call the meeting page makes, so this test does not pin that endpoint.
 */
class RapporteurDecisionReviewTest extends TestCase
{
    use HoldsPaperMeetings;
    use RefreshDatabase;

    private User $rapporteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->rapporteur = $this->userWith('R02');
    }

    public function test_the_authoritys_decision_waits_for_the_rapporteur(): void
    {
        $file = $this->fileAt('approval_by_authority', 'awaiting_municipal_approval', 'dean');
        Notification::fake();

        $file = $this->authority('R07', $file, 'approve');

        $this->assertSame('rapporteur_decision_review', $file->currentStage->code);
        $this->assertSame('authority_approved', $file->status->code);
        // The approval ledger still records العميد's approve.
        $this->assertTrue(Approval::where('request_id', $file->id)->where('level', 3)->exists());
        Notification::assertNotSentTo($file->subject, RequestNoticeNotification::class);
        Notification::assertSentTo($this->rapporteur, ActionRequiredNotification::class);

        $responsibility = app(RequestResponsibilityService::class)->for($file);
        $this->assertSame('committee_rapporteur', $responsibility['responsible']['code']);
        $this->assertSame('review_authority_decision', $responsibility['next_action']['code']);
    }

    public function test_confirming_an_approval_makes_it_final_and_tells_the_employee(): void
    {
        $file = $this->authority('R07', $this->fileAt('approval_by_authority', 'awaiting_municipal_approval', 'dean'), 'approve');
        $hr = $this->userWith('R12');
        Notification::fake();

        $this->act($this->rapporteur, $file, 'confirm_decision')->assertOk();

        $file->refresh();
        $this->assertSame('final_approval_archiving', $file->currentStage->code);
        $this->assertSame('final_approved', $file->status->code);
        Notification::assertSentTo($file->subject, RequestNoticeNotification::class,
            fn (RequestNoticeNotification $notice) => $notice->toArray($file->subject)['moment'] === 'final_approval');
        Notification::assertSentTo($hr, RequestNoticeCopyNotification::class);
    }

    public function test_a_confirmed_rejection_carries_the_authoritys_reason(): void
    {
        $file = $this->authority('R07', $this->fileAt('approval_by_authority', 'awaiting_municipal_approval', 'dean'), 'reject', 'لا تتوفر الدرجة الشاغرة');
        $this->assertSame('authority_rejected', $file->status->code);
        Notification::fake();

        $this->act($this->rapporteur, $file, 'confirm_decision')->assertOk();

        $this->assertSame('not_approved', $file->fresh()->status->code);
        Notification::assertSentTo($file->subject, RequestNoticeNotification::class, function (RequestNoticeNotification $notice) use ($file) {
            $payload = $notice->toArray($file->subject);

            return $payload['moment'] === 'not_approved' && str_contains($payload['body_ar'], 'لا تتوفر الدرجة الشاغرة');
        });
    }

    public function test_a_return_needs_a_note(): void
    {
        $file = $this->authority('R07', $this->fileAt('approval_by_authority', 'awaiting_municipal_approval', 'dean'), 'approve');

        $this->act($this->rapporteur, $file, 'return_to_authority')->assertStatus(422);

        $this->assertSame('rapporteur_decision_review', $file->fresh()->currentStage->code);
    }

    public function test_a_dean_file_goes_back_to_the_dean_who_decides_again(): void
    {
        $file = $this->authority('R07', $this->fileAt('approval_by_authority', 'awaiting_municipal_approval', 'dean'), 'approve');
        $dean = User::whereHas('roles', fn ($q) => $q->where('code', 'R07'))->latest('id')->first();
        Notification::fake();

        $this->act($this->rapporteur, $file, 'return_to_authority', 'المحضر لا يطابق القرار')->assertOk();

        $file->refresh();
        $this->assertSame('approval_by_authority', $file->currentStage->code);
        $this->assertSame('awaiting_municipal_approval', $file->status->code);
        Notification::assertNotSentTo($file->subject, RequestNoticeNotification::class);
        Notification::assertSentTo($dean, ActionRequiredNotification::class);

        $this->assertSame('authority_rejected', $this->authority('R07', $file, 'reject', 'بعد المراجعة لا يعتمد')->status->code);
    }

    public function test_a_minister_file_goes_back_to_the_minister(): void
    {
        $file = $this->authority('R06', $this->fileAt('local_governance_ministry', 'awaiting_central_approval', 'minister'), 'approve');

        $this->act($this->rapporteur, $file, 'return_to_authority', 'يرجى مراجعة القرار')->assertOk();

        $file->refresh();
        $this->assertSame('local_governance_ministry', $file->currentStage->code);
        $this->assertSame('awaiting_central_approval', $file->status->code);
    }

    /** A file R08 escalated to the ministry on a missed deadline returns there with the status it held. */
    public function test_an_escalated_file_goes_back_to_the_ministry_as_it_was(): void
    {
        $file = $this->fileAt('approval_by_authority', 'awaiting_municipal_approval', 'dean');
        $file->forceFill(['overdue_at' => now()])->save();
        app(WorkflowService::class)->transition($file, 'deadline_expired', $this->userWith('R08'), 'تجاوز المهلة');
        $file = $this->authority('R06', $file->fresh(), 'approve');
        $this->assertSame('rapporteur_decision_review', $file->currentStage->code);

        $this->act($this->rapporteur, $file, 'return_to_authority', 'يرجى مراجعة القرار')->assertOk();

        $file->refresh();
        $this->assertSame('local_governance_ministry', $file->currentStage->code);
        $this->assertSame('in_review', $file->status->code);
    }

    public function test_the_authority_cannot_confirm_its_own_decision(): void
    {
        $file = $this->authority('R07', $this->fileAt('approval_by_authority', 'awaiting_municipal_approval', 'dean'), 'approve');

        $this->act($this->userWith('R07'), $file, 'confirm_decision')->assertStatus(404);
        $this->act($this->userWith('R07'), $file, 'return_to_authority', 'ملاحظة')->assertStatus(404);

        $this->assertSame('rapporteur_decision_review', $file->fresh()->currentStage->code);
    }

    public function test_the_confirmation_is_the_rapporteurs_task(): void
    {
        $file = $this->authority('R07', $this->fileAt('approval_by_authority', 'awaiting_municipal_approval', 'dean'), 'approve');

        $sources = collect($this->actingAs($this->rapporteur, 'sanctum')->getJson('/api/my-tasks')->assertOk()->json('data.sources'))->keyBy('code');
        $task = collect($sources['workflow_step']['tasks'] ?? [])->firstWhere('title', $file->title);

        $this->assertNotNull($task);
        $this->assertSame('confirm_decision', $task['action']);
    }

    public function test_the_standard_workflow_still_validates(): void
    {
        $report = app(WorkflowVersionValidator::class)->report(Workflow::standard()->publishedVersion);

        $this->assertSame([], $report['errors']);
    }

    private function fileAt(string $stageCode, string $statusCode, string $authority): Request
    {
        $subject = User::factory()->create(['is_active' => true]);
        $file = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب بانتظار جهة الاعتماد '.fake()->unique()->numberBetween(1, 9999),
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'decision_grade' => 5,
            'approving_authority' => $authority,
            'created_by_user_id' => $subject->id,
            'subject_user_id' => $subject->id,
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'submitted_at' => now(),
        ]);
        $file->forceFill(['current_stage_id' => $file->stageByCode($stageCode)->id])->save();

        return $file->fresh();
    }

    /** The authority's decision, as MeetingResultsController::recordAuthorityDecisions() applies it. */
    private function authority(string $roleCode, Request $file, string $decision, ?string $note = 'قرار جهة الاعتماد'): Request
    {
        return app(WorkflowService::class)->transition($file, $decision, $this->userWith($roleCode), $note)->fresh();
    }

    private function act(User $actor, Request $file, string $action, ?string $comment = null)
    {
        return $this->actingAs($actor, 'sanctum')
            ->postJson("/api/requests/{$file->id}/transition", array_filter(['action' => $action, 'comment' => $comment]));
    }
}
