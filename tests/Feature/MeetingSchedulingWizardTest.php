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
use Tests\SitsOnCommittee;
use Tests\TestCase;

/** Stage 30 — the scheduling wizard's new meeting fields, RSVP tracking, and the send-invitations action. */
class MeetingSchedulingWizardTest extends TestCase
{
    use RefreshDatabase;
    use SitsOnCommittee;

    public function test_scheduling_a_meeting_persists_the_new_wizard_fields(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Stage 102 — the مقرر schedules; the type, chair and rapporteur are
        // no longer the client's to choose, so the values POSTed for them
        // below are deliberately ones the server must ignore.
        $rapporteur = $this->userWithRole('R02');
        $stranger = $this->userWithRole('R04');
        $committee = Committee::create(['name_ar' => 'لجنة المشتريات']);
        $seats = $this->fillFiveSeats($committee, ['rapporteur' => $rapporteur]);
        $head = $seats['chair'];

        $response = $this->actingAs($rapporteur, 'sanctum')
            ->postJson('/api/meetings', [
                'committee_id' => $committee->id,
                // Stage 70 — meeting_number is deliberately still POSTed here to
                // prove it is now IGNORED: the server mints Appendix 15's own
                // PM-MTG code and a client value can no longer override it.
                'meeting_number' => '3/2026',
                'title' => 'الاجتماع الدوري الثالث',
                'meeting_type' => 'extraordinary',
                'scheduled_at' => now()->addDay()->toDateTimeString(),
                'location' => 'قاعة الاجتماعات',
                'chairman_user_id' => $stranger->id,
                'rapporteur_user_id' => $stranger->id,
                'expected_duration_minutes' => 90,
                'agenda_deadline' => now()->addHours(12)->toDateTimeString(),
                'description' => 'مراجعة طلبات الترقية.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.meeting_number', 'PM-MTG/'.now()->format('Y').'/01')
            ->assertJsonPath('data.meeting_type', 'regular')
            ->assertJsonPath('data.expected_duration_minutes', 90)
            ->assertJsonPath('data.description', 'مراجعة طلبات الترقية.')
            ->assertJsonPath('data.chairman.id', $head->id)
            ->assertJsonPath('data.rapporteur.id', $rapporteur->id);

        // The chair's auto-invitation starts pending, unresponded.
        $chairRow = collect($response->json('data.attendees'))->firstWhere('user.id', $head->id);
        $this->assertSame('pending', $chairRow['invitation_status']);
        $this->assertNull($chairRow['responded_at']);

        $this->assertDatabaseHas('meetings', [
            'id' => $response->json('data.id'),
            'meeting_number' => 'PM-MTG/'.now()->format('Y').'/01',
            'meeting_type' => 'regular',
            'chairman_user_id' => $head->id,
            'rapporteur_user_id' => $rapporteur->id,
        ]);
    }

    /**
     * Stage 102 replaced "meeting_type must be a known value" (there is one
     * type now): the committee meets once a month, and a cancelled sitting
     * does not use up its month.
     */
    public function test_a_committee_meets_at_most_once_a_calendar_month(): void
    {
        $this->seed(DatabaseSeeder::class);

        $rapporteur = $this->userWithRole('R02');
        $committee = Committee::create(['name_ar' => 'لجنة المشتريات']);
        $this->fillFiveSeats($committee, ['rapporteur' => $rapporteur]);
        $month = now()->addMonth()->startOfMonth();

        $first = $this->actingAs($rapporteur, 'sanctum')
            ->postJson('/api/meetings', ['committee_id' => $committee->id, 'title' => 'الأول', 'scheduled_at' => $month->copy()->addDays(2)->toDateTimeString()])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson('/api/meetings', ['committee_id' => $committee->id, 'title' => 'الثاني', 'scheduled_at' => $month->copy()->addDays(20)->toDateTimeString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scheduled_at');

        Meeting::whereKey($first)->update(['status' => 'cancelled']);

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson('/api/meetings', ['committee_id' => $committee->id, 'title' => 'الثاني', 'scheduled_at' => $month->copy()->addDays(20)->toDateTimeString()])
            ->assertCreated();
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

    /**
     * Stage 102 — the RSVP is each member's own answer (MeetingController::
     * respond(), covered by CommitteeSinglePathTest); the roll-call endpoint
     * no longer lets staff record it for them.
     */
    public function test_staff_can_no_longer_record_a_members_rsvp(): void
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

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/attendees/{$attendee->id}", ['invitation_status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.invitation_status', 'pending');

        $this->assertDatabaseHas('meeting_attendees', [
            'id' => $attendee->id,
            'invitation_status' => 'pending',
            'responded_at' => null,
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
