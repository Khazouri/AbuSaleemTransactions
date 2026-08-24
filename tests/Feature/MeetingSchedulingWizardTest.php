<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Meeting;
use App\Models\Role;
use App\Models\User;
use App\Notifications\MeetingScheduledNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Stage 30 — the scheduling wizard's new meeting fields, RSVP tracking, and the send-invitations action. */
class MeetingSchedulingWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduling_a_meeting_persists_the_new_wizard_fields(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $rapporteur = $this->userWithRole('R04');
        $committee = Committee::create(['name_ar' => 'لجنة المشتريات']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);

        $response = $this->actingAs($head, 'sanctum')
            ->postJson('/api/meetings', [
                'committee_id' => $committee->id,
                'meeting_number' => '3/2026',
                'title' => 'الاجتماع الدوري الثالث',
                'meeting_type' => 'extraordinary',
                'scheduled_at' => now()->addDay()->toDateTimeString(),
                'location' => 'قاعة الاجتماعات',
                'chairman_user_id' => $head->id,
                'rapporteur_user_id' => $rapporteur->id,
                'expected_duration_minutes' => 90,
                'agenda_deadline' => now()->addHours(12)->toDateTimeString(),
                'description' => 'مراجعة طلبات الترقية.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.meeting_number', '3/2026')
            ->assertJsonPath('data.meeting_type', 'extraordinary')
            ->assertJsonPath('data.expected_duration_minutes', 90)
            ->assertJsonPath('data.description', 'مراجعة طلبات الترقية.')
            ->assertJsonPath('data.chairman.id', $head->id)
            ->assertJsonPath('data.rapporteur.id', $rapporteur->id);

        // The committee's own auto-invited attendee starts pending, unresponded.
        $this->assertSame('pending', $response->json('data.attendees.0.invitation_status'));
        $this->assertNull($response->json('data.attendees.0.responded_at'));

        $this->assertDatabaseHas('meetings', [
            'id' => $response->json('data.id'),
            'meeting_number' => '3/2026',
            'meeting_type' => 'extraordinary',
            'chairman_user_id' => $head->id,
            'rapporteur_user_id' => $rapporteur->id,
        ]);
    }

    public function test_meeting_type_must_be_a_known_value(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $committee = Committee::create(['name_ar' => 'لجنة المشتريات']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);

        $this->actingAs($head, 'sanctum')
            ->postJson('/api/meetings', [
                'committee_id' => $committee->id,
                'title' => 'اجتماع',
                'meeting_type' => 'not-a-real-type',
                'scheduled_at' => now()->addDay()->toDateTimeString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('meeting_type');
    }

    public function test_send_invitations_notifies_current_attendees(): void
    {
        Notification::fake();
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');
        $committee = Committee::create(['name_ar' => 'لجنة المشتريات']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id]);
        $meeting->attendees()->create(['user_id' => $member->id]);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/send-invitations")
            ->assertOk();

        // The actor (head) is excluded by NotificationDispatcher::meetingScheduled; only the member is notified.
        Notification::assertSentTo($member, MeetingScheduledNotification::class);
        Notification::assertNotSentTo($head, MeetingScheduledNotification::class);
    }

    public function test_marking_invitation_status_stamps_responded_at(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $committee = Committee::create(['name_ar' => 'لجنة المشتريات']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $attendee = $meeting->attendees()->create(['user_id' => $head->id]);

        $this->assertNull($attendee->responded_at);

        $response = $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/attendees/{$attendee->id}", ['invitation_status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.invitation_status', 'confirmed');

        $this->assertNotNull($response->json('data.responded_at'));
        $this->assertDatabaseHas('meeting_attendees', [
            'id' => $attendee->id,
            'invitation_status' => 'confirmed',
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
