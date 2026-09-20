<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\NotificationSetting;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\ActionRequiredNotification;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\RequestStageChangedNotification;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    /**
     * The Stage 23 done-when: a transition fires notifications, and only on
     * the channels the recipient has enabled.
     *
     * Stage 4 forwards to stage 5, whose configured role is R05 — so the
     * "waiting for you" message goes to the admin manager, the creator gets
     * the informational update, and the person who pressed the button gets
     * neither. The channel assertions are the point: `stage_changed` defaults
     * to in-app only, `action_required` to in-app plus email.
     */
    public function test_a_transition_notifies_the_creator_and_the_next_actor_on_their_enabled_channels_only(): void
    {
        Notification::fake();

        $creator = $this->userWithRole('R01');
        $actor = $this->userWithRole('R02');
        // The fixture needs a hop whose actor and next actor are different
        // parties, or there is no handoff left to assert. Stage 86 moved it to
        // `reviewer_review -> observations` because `observations` had become
        // R09's; Stage 96 gave R02 the whole pre-committee chain back, which
        // makes that hop same-role again. `forward_to_committee ->
        // receive_from_committee` is the first hop after which the file
        // genuinely changes hands: R02 moves it, R03 (the committee) acts next.
        $nextActor = $this->userWithRole('R03');
        $bystander = $this->userWithRole('R04');
        $requestRecord = $this->requestAtStage('forward_to_committee', $creator);

        app(WorkflowService::class)->transition($requestRecord, 'forward', $actor);

        Notification::assertSentTo(
            $creator,
            RequestStageChangedNotification::class,
            fn ($notification, array $channels) => $channels === ['database'],
        );
        Notification::assertSentTo(
            $nextActor,
            ActionRequiredNotification::class,
            fn ($notification, array $channels) => $channels === ['database', 'mail'],
        );

        // The actor already knows what they just did, and R04 holds no rule
        // out of the destination stage, so neither is on the list.
        Notification::assertNotSentTo($actor, RequestStageChangedNotification::class);
        Notification::assertNotSentTo($actor, ActionRequiredNotification::class);
        Notification::assertNotSentTo($bystander, ActionRequiredNotification::class);
    }

    /**
     * Diagram-alignment redesign (see AGENT_NOTES.md): direct_manager_review's
     * only outbound row (`forward`, to administrative_routing) is
     * manager-gated with required_role_id null — no fixed role can be
     * resolved for it. Without the actorsForStage() fix, a request landing
     * there would notify nobody at all; the manager must hear about it the
     * same way any other next-actor would.
     */
    public function test_landing_at_direct_manager_review_notifies_the_creators_manager(): void
    {
        Notification::fake();

        $manager = $this->userWithRole('R05');
        $employee = $this->userWithRole('R01');
        $employee->manager_id = $manager->id;
        $employee->save();

        $requestRecord = $this->requestAtStage('receive_from_municipality', $employee);

        app(WorkflowService::class)->applySystemTransition(
            $requestRecord,
            'receive_from_municipality',
            'submit',
            $employee,
        );

        Notification::assertSentTo($manager, ActionRequiredNotification::class);

        // The employee submitted their own request, so they are both the
        // creator and the actor — stageChanged() must not notify them of
        // their own action under either event type.
        Notification::assertNotSentTo($employee, ActionRequiredNotification::class);
        Notification::assertNotSentTo($employee, RequestStageChangedNotification::class);
    }

    /** A stored preference overrides the default, and clearing every channel silences the event. */
    public function test_stored_preferences_narrow_the_channels_and_clearing_them_all_sends_nothing(): void
    {
        Notification::fake();

        $creator = $this->userWithRole('R01');
        $actor = $this->userWithRole('R02');
        $nextActor = $this->userWithRole('R03');
        $requestRecord = $this->requestAtStage('forward_to_committee', $creator);

        // The next actor drops email but keeps the bell...
        NotificationSetting::create([
            'user_id' => $nextActor->id,
            'event_type' => 'action_required',
            'in_app' => true,
            'email' => false,
            'sms' => false,
        ]);
        // ...while the creator mutes the running commentary entirely.
        NotificationSetting::create([
            'user_id' => $creator->id,
            'event_type' => 'stage_changed',
            'in_app' => false,
            'email' => false,
            'sms' => false,
        ]);

        app(WorkflowService::class)->transition($requestRecord, 'forward', $actor);

        Notification::assertSentTo(
            $nextActor,
            ActionRequiredNotification::class,
            fn ($notification, array $channels) => $channels === ['database'],
        );
        Notification::assertNotSentTo($creator, RequestStageChangedNotification::class);
    }

    /**
     * SMS is dropped for a user with no number rather than queued and failed
     * in a worker, where nobody would see it.
     */
    public function test_sms_is_only_offered_to_a_user_who_has_a_phone_number(): void
    {
        $user = $this->userWithRole('R05');
        NotificationSetting::create([
            'user_id' => $user->id,
            'event_type' => 'action_required',
            'in_app' => true,
            'email' => false,
            'sms' => true,
        ]);

        $this->assertSame(
            ['database'],
            NotificationSetting::channelsFor($user->refresh(), 'action_required'),
        );

        $user->forceFill(['phone' => '+218910000000'])->save();

        $this->assertSame(
            ['database', SmsChannel::class],
            NotificationSetting::channelsFor($user->refresh(), 'action_required'),
        );
    }

    /**
     * End to end without a fake: the queued notification really lands in the
     * notifications table and shows up on the caller's bell. This also proves
     * the after-commit deferral doesn't swallow delivery.
     */
    public function test_a_real_transition_writes_an_in_app_notification_the_recipient_can_read_and_dismiss(): void
    {
        $creator = $this->userWithRole('R01');
        $actor = $this->userWithRole('R02');
        $requestRecord = $this->requestAtStage('reviewer_review', $creator);

        app(WorkflowService::class)->transition($requestRecord, 'forward', $actor);

        $this->assertSame(1, $creator->unreadNotifications()->count());

        $this->actingAs($creator)
            ->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread', 1);

        $listed = $this->actingAs($creator)->getJson('/api/notifications')->assertOk();
        $listed->assertJsonPath('data.0.event_type', 'stage_changed');
        $listed->assertJsonPath('data.0.request_id', $requestRecord->id);

        $this->actingAs($creator)
            ->postJson("/api/notifications/{$listed->json('data.0.id')}/read")
            ->assertOk();

        $this->assertSame(0, $creator->refresh()->unreadNotifications()->count());
    }

    /** The endpoints are scoped to the caller — a permission grant is not a window onto someone else's queue. */
    public function test_the_endpoints_never_expose_or_mutate_another_users_notifications(): void
    {
        $creator = $this->userWithRole('R01');
        $actor = $this->userWithRole('R02');
        $stranger = $this->userWithRole('R01');

        app(WorkflowService::class)->transition($this->requestAtStage('reviewer_review', $creator), 'forward', $actor);

        $this->actingAs($stranger)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $othersId = $creator->notifications()->value('id');

        $this->actingAs($stranger)
            ->postJson("/api/notifications/{$othersId}/read")
            ->assertNotFound();

        $this->assertSame(1, $creator->unreadNotifications()->count());
    }

    /** Preferences round-trip, and only registered event types may be written. */
    public function test_preferences_are_saved_for_the_caller_and_unknown_event_types_are_rejected(): void
    {
        $user = $this->userWithRole('R05');

        $this->actingAs($user)
            ->getJson('/api/notifications/settings')
            ->assertOk()
            // Untouched events come back as the registry defaults, so the
            // screen never has to guess.
            ->assertJsonPath('data.settings.action_required.email', true)
            ->assertJsonPath('data.phone', null);

        $this->actingAs($user)
            ->putJson('/api/notifications/settings', [
                'phone' => '+218910000000',
                'settings' => [
                    ['event_type' => 'action_required', 'in_app' => true, 'email' => false, 'sms' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.action_required.email', false)
            ->assertJsonPath('data.settings.action_required.sms', true)
            ->assertJsonPath('data.phone', '+218910000000')
            // Events the payload didn't name keep their defaults rather than
            // being reset to whatever the client happened to send.
            ->assertJsonPath('data.settings.meeting_scheduled.email', true);

        $this->assertDatabaseHas('notification_settings', [
            'user_id' => $user->id,
            'event_type' => 'action_required',
            'sms' => true,
        ]);

        $this->actingAs($user)
            ->putJson('/api/notifications/settings', [
                'settings' => [
                    ['event_type' => 'not_a_real_event', 'in_app' => true, 'email' => false, 'sms' => false],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('settings.0.event_type');
    }

    private function requestAtStage(string $stageCode, User $creator): Request
    {
        return Request::create([
            'reference_number' => '2026-ADM-'.fake()->unique()->numerify('######'),
            'title' => 'اختبار الإشعارات',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_review')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now(),
            'decision_grade' => 10,
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
