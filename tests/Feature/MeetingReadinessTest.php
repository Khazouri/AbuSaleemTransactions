<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingTransaction;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionStatus;
use App\Models\TransactionType;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Stage 33 — the pre-meeting readiness gate. See the AGENT_NOTES Stage 33
 * entry for why each of the four percentages/quorum/exceptions is defined
 * the way it is — there's no design-doc spec beyond the field names.
 */
class MeetingReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fully_prepared_meeting_reports_no_exceptions_and_is_ready(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee, $members] = $this->committeeWithMembers(2);
        $meeting = $this->scheduleMeeting($committee, $head);
        foreach ($members as $member) {
            $this->invite($meeting, $member, 'confirmed');
        }
        $this->addRequestItem($meeting, withFile: true, withPriorityAndTime: true);

        $response = $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/readiness")
            ->assertOk();

        $this->assertTrue($response->json('data.ready'));
        $this->assertSame([], $response->json('data.exceptions'));
        $this->assertSame(100, $response->json('data.file_percentage'));
        $this->assertSame(100, $response->json('data.member_percentage'));
        $this->assertSame(100, $response->json('data.agenda_percentage'));
        $this->assertSame(100, $response->json('data.invitation_percentage'));
        $this->assertTrue($response->json('data.quorum_met'));
    }

    public function test_empty_agenda_is_an_exception(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee, $members] = $this->committeeWithMembers(2);
        $meeting = $this->scheduleMeeting($committee, $head);
        foreach ($members as $member) {
            $this->invite($meeting, $member, 'confirmed');
        }

        $codes = $this->exceptionCodes($head, $meeting);
        $this->assertContains('empty_agenda', $codes);
    }

    public function test_a_request_item_without_an_attachment_is_a_missing_files_exception(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee, $members] = $this->committeeWithMembers(2);
        $meeting = $this->scheduleMeeting($committee, $head);
        foreach ($members as $member) {
            $this->invite($meeting, $member, 'confirmed');
        }
        $this->addRequestItem($meeting, withFile: false, withPriorityAndTime: true);

        $response = $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/readiness")
            ->assertOk();

        $this->assertLessThan(100, $response->json('data.file_percentage'));
        $this->assertContains('missing_files', collect($response->json('data.exceptions'))->pluck('code'));
    }

    public function test_below_quorum_confirmations_is_a_quorum_not_met_exception(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee, $members] = $this->committeeWithMembers(3);
        $meeting = $this->scheduleMeeting($committee, $head);
        // Only one of three confirms — majority of 3 requires 2.
        $this->invite($meeting, $members[0], 'confirmed');
        $this->invite($meeting, $members[1], 'pending');
        $this->invite($meeting, $members[2], 'pending');
        $this->addRequestItem($meeting, withFile: true, withPriorityAndTime: true);

        $response = $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/readiness")
            ->assertOk();

        $this->assertFalse($response->json('data.quorum_met'));
        $this->assertSame(2, $response->json('data.quorum_required'));
        $this->assertSame(1, $response->json('data.quorum_confirmed'));
        $this->assertContains('quorum_not_met', collect($response->json('data.exceptions'))->pluck('code'));
    }

    public function test_a_committee_member_not_invited_is_an_unconfirmed_members_exception(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee, $members] = $this->committeeWithMembers(2);
        $meeting = $this->scheduleMeeting($committee, $head);
        // Only invite one of the two active members.
        $this->invite($meeting, $members[0], 'confirmed');
        $this->addRequestItem($meeting, withFile: true, withPriorityAndTime: true);

        $codes = $this->exceptionCodes($head, $meeting);
        $this->assertContains('unconfirmed_members', $codes);
    }

    public function test_an_agenda_item_missing_priority_or_time_is_an_incomplete_agenda_items_exception(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee, $members] = $this->committeeWithMembers(2);
        $meeting = $this->scheduleMeeting($committee, $head);
        foreach ($members as $member) {
            $this->invite($meeting, $member, 'confirmed');
        }
        $this->addRequestItem($meeting, withFile: true, withPriorityAndTime: false);

        $codes = $this->exceptionCodes($head, $meeting);
        $this->assertContains('incomplete_agenda_items', $codes);
    }

    public function test_an_unanswered_invitation_is_a_pending_invitations_exception(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee, $members] = $this->committeeWithMembers(2);
        $meeting = $this->scheduleMeeting($committee, $head);
        $this->invite($meeting, $members[0], 'confirmed');
        $this->invite($meeting, $members[1], 'pending');
        $this->addRequestItem($meeting, withFile: true, withPriorityAndTime: true);

        $codes = $this->exceptionCodes($head, $meeting);
        $this->assertContains('pending_invitations', $codes);
    }

    public function test_convene_succeeds_without_a_reason_when_ready(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee, $members] = $this->committeeWithMembers(2);
        $meeting = $this->scheduleMeeting($committee, $head);
        foreach ($members as $member) {
            $this->invite($meeting, $member, 'confirmed');
        }
        $this->addRequestItem($meeting, withFile: true, withPriorityAndTime: true);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/convene")
            ->assertOk()
            ->assertJsonPath('data.readiness_override_reason', null);

        $this->assertNotNull($meeting->refresh()->convened_at);
        $this->assertSame($head->id, $meeting->convened_by_user_id);
    }

    public function test_convene_requires_a_reason_when_not_ready_then_succeeds_with_one(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee] = $this->committeeWithMembers(2);
        $meeting = $this->scheduleMeeting($committee, $head);
        // No agenda, no invitations confirmed — definitely not ready.

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/convene")
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/convene", ['reason' => 'قرار استثنائي من الرئيس'])
            ->assertOk()
            ->assertJsonPath('data.readiness_override_reason', 'قرار استثنائي من الرئيس');

        $this->assertNotNull($meeting->refresh()->convened_at);
    }

    public function test_convene_is_rejected_once_the_meeting_is_no_longer_scheduled(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee] = $this->committeeWithMembers(2);
        $meeting = $this->scheduleMeeting($committee, $head);
        $meeting->update(['status' => 'completed']);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/convene", ['reason' => 'أي سبب'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_a_member_can_view_readiness_but_cannot_convene(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');

        [$committee] = $this->committeeWithMembers(2);
        $meeting = $this->scheduleMeeting($committee, $head);

        $this->actingAs($member, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/readiness")
            ->assertOk();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/convene", ['reason' => 'أي سبب'])
            ->assertStatus(403);
    }

    public function test_dashboard_next_meeting_readiness_matches_the_dedicated_endpoint(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');

        [$committee, $members] = $this->committeeWithMembers(2);
        $meeting = $this->scheduleMeeting($committee, $head, now()->addDay());
        $this->invite($meeting, $members[0], 'confirmed');
        // Second member left pending -> not fully ready.

        $direct = $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/readiness")
            ->assertOk();

        $dashboard = $this->actingAs($head, 'sanctum')
            ->getJson('/api/meetings/dashboard')
            ->assertOk();

        $this->assertSame($direct->json('data.ready'), $dashboard->json('data.next_meeting.readiness.ready'));
        $this->assertSame(
            count($direct->json('data.exceptions')),
            $dashboard->json('data.next_meeting.readiness.exceptions_count'),
        );
    }

    // --- Fixture helpers -----------------------------------------------------------

    private function exceptionCodes(User $actor, Meeting $meeting): Collection
    {
        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/readiness")
            ->assertOk();

        return collect($response->json('data.exceptions'))->pluck('code');
    }

    /** @return array{0: Committee, 1: User[]} */
    private function committeeWithMembers(int $count): array
    {
        $committee = Committee::create(['name_ar' => 'لجنة اختبار الجاهزية']);
        $members = [];
        for ($i = 0; $i < $count; $i++) {
            $user = User::factory()->create(['is_active' => true]);
            CommitteeMember::create(['committee_id' => $committee->id, 'user_id' => $user->id]);
            $members[] = $user;
        }

        return [$committee, $members];
    }

    private function scheduleMeeting(Committee $committee, User $creator, ?Carbon $at = null): Meeting
    {
        return Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع اختبار الجاهزية',
            'status' => 'scheduled',
            'scheduled_at' => $at ?? now()->addDays(2),
            'created_by_user_id' => $creator->id,
        ]);
    }

    private function invite(Meeting $meeting, User $user, string $invitationStatus): MeetingAttendee
    {
        return MeetingAttendee::create([
            'meeting_id' => $meeting->id,
            'user_id' => $user->id,
            'invitation_status' => $invitationStatus,
            'responded_at' => $invitationStatus === 'pending' ? null : now(),
        ]);
    }

    private function addRequestItem(Meeting $meeting, bool $withFile, bool $withPriorityAndTime): MeetingTransaction
    {
        $transaction = Transaction::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب اختبار الجاهزية',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'transaction_type_id' => TransactionType::where('code', 'PROM')->value('id'),
            'status_id' => TransactionStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'submitted_at' => now(),
        ]);

        if ($withFile) {
            Attachment::create([
                'transaction_id' => $transaction->id,
                'path' => 'attachments/test.pdf',
                'original_name' => 'test.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 1024,
            ]);
        }

        return MeetingTransaction::create([
            'meeting_id' => $meeting->id,
            'transaction_id' => $transaction->id,
            'agenda_order' => 1,
            'item_type' => 'employee_request',
            'priority' => $withPriorityAndTime ? 'high' : null,
            'estimated_minutes' => $withPriorityAndTime ? 15 : null,
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
