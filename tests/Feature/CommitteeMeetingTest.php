<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PassesControlGates;
use Tests\SitsOnCommittee;
use Tests\TestCase;

class CommitteeMeetingTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;
    use SitsOnCommittee;

    /**
     * Stage 102 — the مقرر schedules; the meeting invites exactly the five
     * seats (a seatless row is never invited) and is only proposed until they
     * all accept. A seat held by a deactivated user blocks scheduling rather
     * than silently shrinking the sitting.
     */
    public function test_the_rapporteur_schedules_a_meeting_that_invites_exactly_the_five_seats(): void
    {
        $this->seed(DatabaseSeeder::class);

        $rapporteur = $this->userWithRole('R02');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $seats = $this->fillFiveSeats($committee, ['rapporteur' => $rapporteur]);
        $seatless = $this->userWithRole('R04');
        $committee->members()->create(['user_id' => $seatless->id]);

        $response = $this->actingAs($rapporteur, 'sanctum')
            ->postJson('/api/meetings', [
                'committee_id' => $committee->id,
                'title' => 'الاجتماع الدوري الأول',
                'scheduled_at' => now()->addDay()->toDateTimeString(),
                'location' => 'قاعة الاجتماعات',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'الاجتماع الدوري الأول')
            ->assertJsonPath('data.status', 'pending_confirmation')
            ->assertJsonPath('data.meeting_type', 'regular');

        $meetingId = $response->json('data.id');
        $attendeeUserIds = collect($response->json('data.attendees'))->pluck('user.id')->sort()->values()->all();

        $this->assertSame(collect($seats)->pluck('id')->sort()->values()->all(), $attendeeUserIds);
        $this->assertDatabaseCount('meeting_attendees', 5);
        $this->assertDatabaseHas('meetings', [
            'id' => $meetingId,
            'chairman_user_id' => $seats['chair']->id,
            'rapporteur_user_id' => $rapporteur->id,
        ]);
        // The مقرر proposed the date, so only their answer is already in.
        $this->assertDatabaseHas('meeting_attendees', ['meeting_id' => $meetingId, 'user_id' => $rapporteur->id, 'invitation_status' => 'confirmed']);
        $this->assertDatabaseHas('meeting_attendees', ['meeting_id' => $meetingId, 'user_id' => $seats['chair']->id, 'invitation_status' => 'pending']);

        $seats['hr_director']->update(['is_active' => false]);
        $this->actingAs($rapporteur, 'sanctum')
            ->postJson('/api/meetings', [
                'committee_id' => $committee->id,
                'title' => 'اجتماع الشهر التالي',
                'scheduled_at' => now()->addMonths(2)->toDateTimeString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('committee_id');
    }

    /**
     * Stage 84 — you may not convene a committee you do not sit on. [D] Art.
     * 12 (أ) 1 gives الدعوة إلى اجتماعات اللجنة to that committee's own chair
     * and Appendix 45 gives إنشاء الاجتماع to its مقرر; neither is a
     * capability over committees the actor has nothing to do with, and the
     * `meetings,add` screen permission alone cannot express "which one".
     */
    public function test_scheduling_is_refused_for_a_committee_the_actor_does_not_sit_on(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Stage 102 — `meetings,add` is the مقرر's alone, so both actors are R02.
        $stranger = $this->userWithRole('R02');
        $seatedRapporteur = $this->userWithRole('R02');

        $committee = Committee::create(['name_ar' => 'لجنة لا ينتمي إليها']);
        $this->fillFiveSeats($committee, ['rapporteur' => $seatedRapporteur]);

        $payload = [
            'committee_id' => $committee->id,
            'title' => 'اجتماع غير مأذون',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ];

        $this->actingAs($stranger, 'sanctum')
            ->postJson('/api/meetings', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('committee_id');

        $this->assertDatabaseCount('meetings', 0);

        // The seated مقرر may, and so may R08 — the same administrative
        // fallback WorkflowService applies to manager-gated transitions (a
        // month later: Stage 102 allows one meeting a month).
        $this->actingAs($seatedRapporteur, 'sanctum')
            ->postJson('/api/meetings', $payload)
            ->assertCreated();

        $this->actingAs($this->userWithRole('R08'), 'sanctum')
            ->postJson('/api/meetings', [
                ...$payload,
                'title' => 'اجتماع بصلاحية إدارية',
                'scheduled_at' => now()->addMonths(2)->toDateTimeString(),
            ])
            ->assertCreated();
    }

    public function test_agenda_add_remove_and_reorder_and_attendance_marking(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $committee = Committee::create(['name_ar' => 'لجنة المشتريات']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع المراجعة',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $attendee = $meeting->attendees()->create(['user_id' => $head->id]);

        $requestOne = $this->request('AAA');
        $requestTwo = $this->request('BBB');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestOne->id])
            ->assertCreated()
            ->assertJsonPath('data.agenda_order', 1);

        $itemTwo = $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestTwo->id])
            ->assertCreated()
            ->assertJsonPath('data.agenda_order', 2)
            ->json('data.id');

        // Adding the same request twice is rejected.
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestOne->id])
            ->assertStatus(422);

        // Reorder: put the second item first.
        $orderedIds = $meeting->agendaItems()->orderByDesc('id')->pluck('id')->all();
        $this->actingAs($head, 'sanctum')
            ->putJson("/api/meetings/{$meeting->id}/agenda/reorder", ['order' => $orderedIds])
            ->assertOk()
            ->assertJsonPath('data.0.id', $orderedIds[0])
            ->assertJsonPath('data.0.agenda_order', 1);

        // Remove the (now second) item.
        $remainingItemId = $meeting->agendaItems()->where('request_id', $requestOne->id)->value('id');
        $this->actingAs($head, 'sanctum')
            ->deleteJson("/api/meetings/{$meeting->id}/agenda/{$remainingItemId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('meeting_requests', ['id' => $remainingItemId]);
        $this->assertDatabaseHas('meeting_requests', ['id' => $itemTwo]);

        // Mark attendance.
        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/attendees/{$attendee->id}", ['attended' => true])
            ->assertOk()
            ->assertJsonPath('data.attended', true);
    }

    public function test_committee_cannot_be_deleted_once_it_has_held_a_meeting(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = $this->userWithRole('R08');
        $committee = Committee::create(['name_ar' => 'لجنة تجريبية']);
        Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع سابق',
            'scheduled_at' => now()->subWeek(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/committees/{$committee->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('committees', ['id' => $committee->id]);
    }

    private function request(string $suffix): Request
    {
        $requestRecord = Request::create([
            'reference_number' => now()->format('Y')."-ADM-{$suffix}".fake()->unique()->numberBetween(1000, 9999),
            'title' => "طلب {$suffix}",
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            // Stage 102 — on the committee's pending list, the only source an
            // agenda draws requests from.
            'status_id' => RequestStatus::where('code', 'registered')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'submitted_at' => now(),
        ]);

        // Stage 68 — [D] Art. 21's legal review now gates agenda insertion.
        // Seeded here so these tests stay about the agenda/attendance
        // mechanics they were written for rather than becoming confounded by
        // a gate that has its own coverage in RequestLegalReviewTest.
        $requestRecord->legalReviews()->create([
            'verdict' => 'sound_ready',
            'reviewed_at' => now(),
        ]);

        // Stage 85 — [F] footer 2 now refuses an agenda insertion for a file
        // that does not cover its type's mandatory [D] Appendix 57 rows.
        // Supplied here so these tests stay about what they were written for;
        // the rule itself has its own coverage in DocumentCompletenessTest.
        $this->supplyRequiredDocuments($requestRecord);

        return $requestRecord;
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
