<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\MeetingInvitationResponseNotification;
use App\Notifications\MeetingScheduledNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\RunsStudySequence;
use Tests\SitsOnCommittee;
use Tests\TestCase;

/**
 * Stage 102 — the user's committee process as the only path: the مقرر
 * schedules a monthly meeting for the five seats, each member answers the date
 * themselves, and once enough accept (Settings, default 3) the meeting is
 * approved and can be held.
 *
 * The pending list, the agenda gate, the monthly rule and the seat/role
 * binding each have their own coverage (CommitteeCandidatesDashboardTest,
 * MeetingAgendaBuilderTest, MeetingSchedulingWizardTest, CommitteeSeatRosterTest);
 * this file is the date-acceptance half, which none of them reach.
 */
class CommitteeSinglePathTest extends TestCase
{
    use RefreshDatabase;
    use RunsStudySequence;
    use SitsOnCommittee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    /** The number comes from Settings (default 3), and the مقرر's own yes counts. */
    public function test_the_meeting_is_approved_once_the_required_number_of_members_accept(): void
    {
        [$seats, $meeting] = $this->proposedMeeting();

        // Not approved yet, so it cannot be held.
        $this->actingAs($seats['chair'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/convene")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->respond($seats['hr_director'], $meeting, 'decline')->assertOk();
        $this->respond($seats['chair'], $meeting, 'accept')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_confirmation');

        $this->respond($seats['legal'], $meeting, 'accept')
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');

        // Approved: answers are closed, and the chair may now convene.
        $this->respond($seats['ministry_delegate'], $meeting, 'accept')->assertStatus(422);
        $this->actingAs($seats['chair'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/convene", ['reason' => 'اختبار'])
            ->assertOk();
    }

    public function test_the_required_number_is_set_on_settings_within_the_five_seats(): void
    {
        $admin = $this->userWithRole('R08');
        $setting = Setting::where('key', Setting::MEETING_ACCEPTANCES_REQUIRED)->firstOrFail();
        $this->assertSame('3', $setting->value);

        foreach (['1', '6', 'ثلاثة', null] as $value) {
            $this->actingAs($admin, 'sanctum')
                ->putJson("/api/settings/{$setting->id}", ['value' => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors('value');
        }

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/settings/{$setting->id}", ['value' => '5'])
            ->assertOk();
        $this->assertSame(5, Setting::meetingAcceptancesRequired());

        [$seats, $meeting] = $this->proposedMeeting();
        foreach (['chair', 'legal', 'hr_director'] as $seat) {
            $this->respond($seats[$seat], $meeting, 'accept')->assertJsonPath('data.status', 'pending_confirmation');
        }
        $this->respond($seats['ministry_delegate'], $meeting, 'accept')->assertJsonPath('data.status', 'scheduled');
    }

    public function test_a_decline_keeps_the_meeting_pending_until_the_rapporteur_proposes_a_new_date(): void
    {
        Notification::fake();
        $this->requireEveryMember();
        [$seats, $meeting] = $this->proposedMeeting();

        foreach (['chair', 'legal', 'hr_director'] as $seat) {
            $this->respond($seats[$seat], $meeting, 'accept')->assertOk();
        }
        $this->respond($seats['ministry_delegate'], $meeting, 'decline')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_confirmation');

        $newDate = $meeting->scheduled_at->copy()->addDay()->toDateTimeString();
        $this->actingAs($seats['rapporteur'], 'sanctum')
            ->putJson("/api/meetings/{$meeting->id}", ['scheduled_at' => $newDate])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_confirmation');

        // Every answer is reset except the proposer's own, and everyone is
        // invited again.
        $answers = $meeting->attendees()->pluck('invitation_status', 'user_id');
        $this->assertSame('confirmed', $answers[$seats['rapporteur']->id]);
        foreach (['chair', 'legal', 'hr_director', 'ministry_delegate'] as $seat) {
            $this->assertSame('pending', $answers[$seats[$seat]->id], $seat);
        }
        Notification::assertSentTo($seats['chair'], MeetingScheduledNotification::class);
    }

    public function test_the_rapporteur_is_told_of_every_answer(): void
    {
        Notification::fake();
        $this->requireEveryMember();
        [$seats, $meeting] = $this->proposedMeeting();

        $this->respond($seats['legal'], $meeting, 'decline')->assertOk();
        Notification::assertSentTo(
            $seats['rapporteur'],
            MeetingInvitationResponseNotification::class,
            fn ($n, $channels, $notifiable) => $n->toArray($notifiable)['response'] === 'decline'
                && $n->toArray($notifiable)['meeting_confirmed'] === false,
        );
        Notification::assertNotSentTo($seats['legal'], MeetingInvitationResponseNotification::class);

        foreach (['chair', 'legal', 'hr_director', 'ministry_delegate'] as $seat) {
            $this->respond($seats[$seat], $meeting, 'accept')->assertOk();
        }
        Notification::assertSentToTimes($seats['rapporteur'], MeetingInvitationResponseNotification::class, 5);
        Notification::assertSentTo(
            $seats['rapporteur'],
            MeetingInvitationResponseNotification::class,
            fn ($n, $channels, $notifiable) => $n->toArray($notifiable)['meeting_confirmed'] === true,
        );
    }

    public function test_a_member_answers_only_for_themselves(): void
    {
        [$seats, $meeting] = $this->proposedMeeting();

        // Someone seated on the committee but not invited to this sitting.
        $outsider = $this->userWithRole('R04');
        $meeting->committee->members()->create(['user_id' => $outsider->id]);

        $this->respond($outsider, $meeting, 'accept')
            ->assertStatus(422)
            ->assertJsonPath('message', 'لست من المدعوين إلى هذا الاجتماع.');

        $this->respond($seats['chair'], $meeting, 'maybe')
            ->assertStatus(422)
            ->assertJsonValidationErrors('response');

        $this->assertDatabaseHas('meeting_attendees', [
            'meeting_id' => $meeting->id,
            'user_id' => $seats['chair']->id,
            'invitation_status' => 'pending',
        ]);
    }

    public function test_an_unanswered_invitation_is_on_the_members_task_inbox(): void
    {
        [$seats, $meeting] = $this->proposedMeeting();

        $source = collect($this->actingAs($seats['legal'], 'sanctum')
            ->getJson('/api/my-tasks')
            ->assertOk()
            ->json('data.sources'))->firstWhere('code', 'meeting_invitation');

        $this->assertSame($meeting->id, $source['tasks'][0]['route']['params']['id']);

        $this->respond($seats['legal'], $meeting, 'accept')->assertOk();

        $this->assertNull(collect($this->actingAs($seats['legal'], 'sanctum')
            ->getJson('/api/my-tasks')
            ->json('data.sources'))->firstWhere('code', 'meeting_invitation'));
    }

    /** مدير إدارة الموارد البشرية sits as a voting member (Art. 10 (أ) 3). */
    public function test_the_hr_director_seat_votes_and_only_the_rapporteur_schedules(): void
    {
        [$seats, $meeting] = $this->proposedMeeting();
        $meeting->update(['status' => 'scheduled', 'agenda_adopted_at' => now()]);
        $meeting->attendees()->update(['attended' => true]);

        $requestRecord = Request::create([
            'title' => 'طلب ترقية',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'registered')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $this->userWithRole('R01')->id,
            'submitted_at' => now(),
        ]);
        $item = $this->completeStudySequence(
            $meeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]),
        );

        $this->actingAs($seats['hr_director'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/votes", ['vote' => 'approve'])
            ->assertCreated();

        $this->assertTrue($seats['hr_director']->hasScreenPermission('meeting_minutes', 'can_add'));

        // The chair accepts the date like everyone else; scheduling is the مقرر's.
        $this->actingAs($seats['chair'], 'sanctum')
            ->postJson('/api/meetings', [
                'committee_id' => $meeting->committee_id,
                'title' => 'اجتماع الشهر التالي',
                'scheduled_at' => now()->addMonths(3)->toDateTimeString(),
            ])
            ->assertForbidden();
    }

    /** These cases need every member's answer before the date is set. */
    private function requireEveryMember(): void
    {
        Setting::where('key', Setting::MEETING_ACCEPTANCES_REQUIRED)->update(['value' => '5']);
    }

    /** @return array{0: array<string, User>, 1: Meeting} */
    private function proposedMeeting(): array
    {
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $seats = $this->fillFiveSeats($committee);

        $meetingId = $this->actingAs($seats['rapporteur'], 'sanctum')
            ->postJson('/api/meetings', [
                'committee_id' => $committee->id,
                'title' => 'الاجتماع الشهري',
                'scheduled_at' => now()->addWeek()->toDateTimeString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_confirmation')
            ->json('data.id');

        return [$seats, Meeting::findOrFail($meetingId)];
    }

    private function respond(User $member, Meeting $meeting, string $response)
    {
        return $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/respond", ['response' => $response]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
