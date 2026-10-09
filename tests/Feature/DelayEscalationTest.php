<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestLegalReview;
use App\Models\RequestStageLog;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\RequestDelayEscalationNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Stage 71 — [D] Appendix 38's delay ladder and the escalation targets it
 * names, which until this stage nothing acted on.
 */
class DelayEscalationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_within_its_target_escalates_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        // requirements_check is 2 days (Appendix 37's فحص المقرر).
        $requestRecord = $this->requestAtStage('requirements_check', 'in_review', now());

        $this->artisan('requests:escalate-delays')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($requestRecord->fresh()->escalation_level);
    }

    public function test_the_final_day_of_the_allowance_notifies_the_current_owner_only(): void
    {
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        // The rapporteur owns requirements_check; the admin manager and the
        // committee head are the أحمر/حرج targets and must stay silent here.
        $owner = $this->userWithRole('R02');
        $adminManager = $this->userWithRole('R05');
        $committeeHead = $this->userWithRole('R03');
        $requestRecord = $this->requestAtStage('requirements_check', 'in_review', now()->subDays(2));

        $this->artisan('requests:escalate-delays')->assertSuccessful();

        Notification::assertSentTo($owner, RequestDelayEscalationNotification::class);
        Notification::assertNotSentTo($adminManager, RequestDelayEscalationNotification::class);
        Notification::assertNotSentTo($committeeHead, RequestDelayEscalationNotification::class);
        $this->assertSame('yellow', $requestRecord->fresh()->escalation_level);
    }

    public function test_past_the_target_escalates_to_the_rapporteur_and_the_admin_manager(): void
    {
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $rapporteur = $this->userWithRole('R02');
        $adminManager = $this->userWithRole('R05');
        $committeeHead = $this->userWithRole('R03');
        $requestRecord = $this->requestAtStage('requirements_check', 'in_review', now()->subDays(3));

        $this->artisan('requests:escalate-delays')->assertSuccessful();

        Notification::assertSentTo($rapporteur, RequestDelayEscalationNotification::class);
        Notification::assertSentTo($adminManager, RequestDelayEscalationNotification::class);
        // حرج's own targets are not reached by أحمر.
        Notification::assertNotSentTo($committeeHead, RequestDelayEscalationNotification::class);
        $this->assertSame('red', $requestRecord->fresh()->escalation_level);
    }

    public function test_a_recorded_legal_deadline_makes_the_same_delay_critical_and_reaches_the_head_and_the_authority(): void
    {
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $adminManager = $this->userWithRole('R05');
        $committeeHead = $this->userWithRole('R03');
        $authority = $this->userWithRole('R07');
        $requestRecord = $this->requestAtStage('requirements_check', 'in_review', now()->subDays(3));
        $this->recordLegalDeadline($requestRecord, 'مدة قانونية: 30 يومًا وفق المادة 135');

        $this->artisan('requests:escalate-delays')->assertSuccessful();

        Notification::assertSentTo($committeeHead, RequestDelayEscalationNotification::class);
        Notification::assertSentTo($authority, RequestDelayEscalationNotification::class);
        // Appendix 38 raises the alarm rather than handing it over: the أحمر
        // targets are still the people who can move the file.
        Notification::assertSentTo($adminManager, RequestDelayEscalationNotification::class);
        $this->assertSame('critical', $requestRecord->fresh()->escalation_level);
    }

    public function test_a_legal_review_with_no_recorded_deadline_stays_red(): void
    {
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $requestRecord = $this->requestAtStage('requirements_check', 'in_review', now()->subDays(3));
        // A review exists, but the "هل توجد مدة قانونية؟" field was left blank.
        $this->recordLegalDeadline($requestRecord, null);

        $this->artisan('requests:escalate-delays')->assertSuccessful();

        $this->assertSame('red', $requestRecord->fresh()->escalation_level);
    }

    public function test_the_same_level_is_never_announced_twice(): void
    {
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $this->userWithRole('R02');
        $requestRecord = $this->requestAtStage('requirements_check', 'in_review', now()->subDays(3));

        $this->artisan('requests:escalate-delays')->assertSuccessful();
        $firstNotifiedAt = $requestRecord->fresh()->escalation_notified_at;
        Notification::assertSentTimes(RequestDelayEscalationNotification::class, 1);

        $this->artisan('requests:escalate-delays')->assertSuccessful();

        Notification::assertSentTimes(RequestDelayEscalationNotification::class, 1);
        $this->assertTrue($firstNotifiedAt->equalTo($requestRecord->fresh()->escalation_notified_at));
    }

    public function test_a_delay_that_worsens_climbs_the_ladder_rather_than_staying_put(): void
    {
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $this->userWithRole('R02');
        $requestRecord = $this->requestAtStage('requirements_check', 'in_review', now()->subDays(2));

        $this->artisan('requests:escalate-delays')->assertSuccessful();
        $this->assertSame('yellow', $requestRecord->fresh()->escalation_level);

        // A day passes: the same request is now past its target.
        $this->travelTo(now()->addDay());
        $this->artisan('requests:escalate-delays')->assertSuccessful();

        $this->assertSame('red', $requestRecord->fresh()->escalation_level);
        Notification::assertSentTimes(RequestDelayEscalationNotification::class, 2);
    }

    public function test_moving_the_request_on_resets_the_ladder_so_the_new_stage_can_escalate_again(): void
    {
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $this->userWithRole('R02');
        $requestRecord = $this->requestAtStage('requirements_check', 'in_review', now()->subDays(3));

        $this->artisan('requests:escalate-delays')->assertSuccessful();
        $this->assertSame('red', $requestRecord->fresh()->escalation_level);

        // The file moves on. No column is cleared and WorkflowService is not
        // involved — the new stage log alone makes the recorded level stale,
        // because escalation is per-stage.
        $this->travelTo(now()->addSecond());
        $nextStage = WorkflowStage::where('code', 'reviewer_review')->firstOrFail();
        $requestRecord->update(['current_stage_id' => $nextStage->id]);
        RequestStageLog::create([
            'request_id' => $requestRecord->id,
            'to_stage_id' => $nextStage->id,
            'action' => 'forward',
            'acted_by_user_id' => $requestRecord->created_by_user_id,
            'acted_at' => now(),
        ]);

        $this->assertNull($requestRecord->fresh()->escalatedLevel());

        // reviewer_review is 3 days; four days later it is late again.
        $this->travelTo(now()->addDays(4));
        $this->artisan('requests:escalate-delays')->assertSuccessful();

        Notification::assertSentTimes(RequestDelayEscalationNotification::class, 2);
        $this->assertSame('red', $requestRecord->fresh()->escalation_level);
    }

    public function test_a_concluded_request_never_escalates(): void
    {
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $this->userWithRole('R02');
        $requestRecord = $this->requestAtStage('requirements_check', 'completed_closed', now()->subDays(60));

        $this->artisan('requests:escalate-delays')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($requestRecord->fresh()->escalation_level);
    }

    public function test_a_stage_with_no_sourced_target_never_escalates(): void
    {
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $this->userWithRole('R02');
        // forward_to_committee's Appendix 37 row is "الاجتماع التالي" — no
        // day count, so no target and nothing to be late against.
        $requestRecord = $this->requestAtStage('forward_to_committee', 'ready', now()->subDays(60));

        $this->artisan('requests:escalate-delays')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($requestRecord->fresh()->escalation_level);
    }

    public function test_the_recorded_escalation_reaches_the_detail_screen(): void
    {
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $requestRecord = $this->requestAtStage('requirements_check', 'in_review', now()->subDays(3));
        $owner = User::findOrFail($requestRecord->created_by_user_id);

        $this->artisan('requests:escalate-delays')->assertSuccessful();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.stage_timeliness.level', 'red')
            ->assertJsonPath('data.stage_timeliness.escalation.level', 'red');
    }

    private function recordLegalDeadline(Request $requestRecord, ?string $deadline): void
    {
        RequestLegalReview::create([
            'request_id' => $requestRecord->id,
            'verdict' => 'sound_ready',
            'legal_deadline' => $deadline,
            'reviewed_by_user_id' => $requestRecord->created_by_user_id,
            'reviewed_at' => now(),
        ]);
    }

    private function requestAtStage(string $stageCode, string $statusCode, \DateTimeInterface $enteredAt): Request
    {
        $stage = WorkflowStage::where('code', $stageCode)->firstOrFail();
        $owner = $this->userWithRole('R01');

        $requestRecord = Request::create([
            'reference_number' => 'PM-COM/'.now()->format('Y').'/'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب موظف',
            'description' => 'طلب لاختبار سلّم التصعيد.',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => $stage->id,
            'created_by_user_id' => $owner->id,
            'submitted_at' => now(),
        ]);

        RequestStageLog::create([
            'request_id' => $requestRecord->id,
            'to_stage_id' => $stage->id,
            'action' => 'test-setup',
            'acted_by_user_id' => $owner->id,
            'acted_at' => $enteredAt,
        ]);

        return $requestRecord;
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
