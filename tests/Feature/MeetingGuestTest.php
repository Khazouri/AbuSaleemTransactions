<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Meeting;
use App\Models\Role;
use App\Models\User;
use App\Notifications\MeetingScheduledNotification;
use App\Services\MeetingMinutesCompiler;
use App\Services\MeetingReadinessService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\SitsOnCommittee;
use Tests\TestCase;

/**
 * The مقرر may invite people beyond the five seats (user decision 2026-10-16),
 * each with the صفة they attend in. An extra member answers the date and
 * nothing more: their yes never counts toward Settings' acceptances, they are
 * not on the roll call, and so never vote, sign or make the quorum.
 */
class MeetingGuestTest extends TestCase
{
    use RefreshDatabase;
    use SitsOnCommittee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_guest_is_invited_with_a_title_and_answers_the_date_without_counting(): void
    {
        Notification::fake();
        [$seats, $meeting, $guest] = $this->meetingWithGuest();

        $this->assertDatabaseHas('meeting_attendees', [
            'meeting_id' => $meeting->id,
            'user_id' => $guest->id,
            'guest_title' => 'مدير الإدارة المالية',
        ]);
        Notification::assertSentTo($guest, MeetingScheduledNotification::class);

        // An unseated guest reaches this meeting, and only it, without its agenda.
        $guest->forgetScreenPermissions();
        $this->assertTrue($guest->hasScreenPermission('meetings', 'can_view'));
        $this->assertFalse($guest->hasScreenPermission('meeting_agenda', 'can_view'));
        $this->actingAs($guest, 'sanctum')->getJson("/api/meetings/{$meeting->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.agenda_items');

        $source = collect($this->actingAs($guest, 'sanctum')->getJson('/api/my-tasks')->json('data.sources'))
            ->firstWhere('code', 'meeting_invitation');
        $this->assertSame($meeting->id, $source['tasks'][0]['route']['params']['id']);

        // The مقرر's yes, the chair's and the guest's: still two seats of three.
        $this->respond($seats['chair'], $meeting, 'accept')->assertOk();
        $this->respond($guest, $meeting, 'accept')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_confirmation');
        $this->respond($seats['legal'], $meeting, 'accept')->assertJsonPath('data.status', 'scheduled');
    }

    public function test_every_guest_needs_a_title_and_a_seat_holder_is_not_a_guest(): void
    {
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $seats = $this->fillFiveSeats($committee);
        $guest = User::factory()->create(['is_active' => true]);

        $this->schedule($seats['rapporteur'], $committee, [['user_id' => $guest->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('guests.0.guest_title');

        $this->schedule($seats['rapporteur'], $committee, [['user_id' => $seats['chair']->id, 'guest_title' => 'رئيس']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('guests');
    }

    public function test_only_the_rapporteur_adds_or_removes_a_guest_while_the_date_is_open(): void
    {
        [$seats, $meeting, $guest] = $this->meetingWithGuest();
        $another = $this->employee();

        $this->actingAs($seats['chair'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/guests", ['user_id' => $another->id, 'guest_title' => 'خبير'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('guests');

        Notification::fake();
        $this->actingAs($seats['rapporteur'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/guests", ['user_id' => $another->id, 'guest_title' => 'خبير قانوني'])
            ->assertOk();
        Notification::assertSentTo($another, MeetingScheduledNotification::class);
        Notification::assertNotSentTo($guest, MeetingScheduledNotification::class);

        // Already on the list, as a guest or a seat.
        $this->actingAs($seats['rapporteur'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/guests", ['user_id' => $seats['legal']->id, 'guest_title' => 'خبير'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');

        $seatRow = $meeting->attendees()->where('user_id', $seats['legal']->id)->first();
        $this->actingAs($seats['rapporteur'], 'sanctum')
            ->deleteJson("/api/meetings/{$meeting->id}/guests/{$seatRow->id}")
            ->assertNotFound();

        $guestRow = $meeting->attendees()->where('user_id', $guest->id)->first();
        $this->actingAs($seats['rapporteur'], 'sanctum')
            ->deleteJson("/api/meetings/{$meeting->id}/guests/{$guestRow->id}")
            ->assertOk();
        $this->assertDatabaseMissing('meeting_attendees', ['id' => $guestRow->id]);

        $guest->forgetScreenPermissions();
        $this->assertFalse($guest->hasScreenPermission('meetings', 'can_view'));

        $meeting->update(['status' => 'scheduled']);
        $this->actingAs($seats['rapporteur'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/guests", ['user_id' => $guest->id, 'guest_title' => 'خبير'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('guests');
    }

    public function test_a_guest_is_off_the_roll_call_readiness_and_quorum_and_listed_apart_in_the_minutes(): void
    {
        [$seats, $meeting, $guest] = $this->meetingWithGuest();
        $meeting->update(['status' => 'scheduled']);
        $meeting->attendees()->whereNull('guest_title')->update(['invitation_status' => 'confirmed']);
        $guestRow = $meeting->attendees()->where('user_id', $guest->id)->first();

        $this->actingAs($seats['chair'], 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/attendees/{$guestRow->id}", ['attended' => true])
            ->assertStatus(422);
        $this->assertFalse($guestRow->fresh()->attended);

        $readiness = app(MeetingReadinessService::class)->compute($meeting->fresh());
        $this->assertNotContains('pending_invitations', array_column($readiness['exceptions'], 'code'));

        $attendance = app(MeetingMinutesCompiler::class)->compile($meeting->fresh())['attendance'];
        $this->assertNotContains($guest->id, array_column($attendance['absent'], 'id'));
        $this->assertSame([['id' => $guest->id, 'name' => $guest->name, 'guest_title' => 'مدير الإدارة المالية']], $attendance['guests']);
    }

    /** @return array{0: array<string, User>, 1: Meeting, 2: User} */
    private function meetingWithGuest(): array
    {
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $seats = $this->fillFiveSeats($committee);
        $guest = $this->employee();

        $meetingId = $this->schedule($seats['rapporteur'], $committee, [
            ['user_id' => $guest->id, 'guest_title' => 'مدير الإدارة المالية'],
        ])->assertCreated()->json('data.id');

        return [$seats, Meeting::findOrFail($meetingId), $guest];
    }

    private function schedule(User $rapporteur, Committee $committee, array $guests)
    {
        return $this->actingAs($rapporteur, 'sanctum')->postJson('/api/meetings', [
            'committee_id' => $committee->id,
            'title' => 'الاجتماع الشهري',
            'scheduled_at' => now()->addWeek()->toDateTimeString(),
            'guests' => $guests,
        ]);
    }

    /** Every account starts as R01 (Stage 103). */
    private function employee(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', 'R01')->value('id'));

        return $user;
    }

    private function respond(User $member, Meeting $meeting, string $response)
    {
        return $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/respond", ['response' => $response]);
    }
}
