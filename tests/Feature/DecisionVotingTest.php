<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingTransaction;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionStatus;
use App\Models\TransactionType;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Stage 21 — committee voting and decision recording drives WorkflowService directly. */
class DecisionVotingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_majority_approve_vote_and_recorded_decision_advances_the_transaction(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated()
            ->assertJsonPath('data.vote', 'approve');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();

        $signature = UploadedFile::fake()->image('signature.png', 10, 10);

        $this->actingAs($head, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", [
                'signature' => $signature,
            ])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'approve')
            ->assertJsonPath('data.votes_approve_count', 2);

        $transaction = $agendaItem->transaction()->first()->fresh();
        $stageEight = WorkflowStage::where('order_no', 8)->firstOrFail();

        $this->assertSame($stageEight->id, $transaction->current_stage_id);
        $this->assertSame('decided', $transaction->status->code);
        $this->assertDatabaseHas('approvals', [
            'transaction_id' => $transaction->id,
            'level' => 2,
            'action' => 'approve',
        ]);
        $this->assertDatabaseHas('decisions', [
            'meeting_transaction_id' => $agendaItem->id,
            'outcome' => 'approve',
            'decided_by_user_id' => $head->id,
        ]);

        // Voting is closed once a decision exists for the agenda item.
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'reject'])
            ->assertStatus(422);
    }

    public function test_a_majority_defer_vote_keeps_the_transaction_at_the_committee_stage(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'defer'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'defer'])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", [
                'comment' => 'الملف غير مكتمل، يؤجل للاجتماع القادم',
            ])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'defer');

        $stageSeven = WorkflowStage::where('order_no', 7)->firstOrFail();
        $transaction = $agendaItem->transaction()->first()->fresh();

        $this->assertSame($stageSeven->id, $transaction->current_stage_id);
        $this->assertSame('deferred', $transaction->status->code);
    }

    public function test_a_tied_vote_cannot_be_recorded_automatically(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'reject'])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", ['comment' => 'تعادل'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('decisions', ['meeting_transaction_id' => $agendaItem->id]);
    }

    public function test_voting_is_restricted_to_committee_members_who_attended(): void
    {
        $this->seed(DatabaseSeeder::class);

        [, , $committee, $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->roles()->attach(Role::where('code', 'R04')->value('id'));

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertStatus(422);

        // A real member who has not been marked as attended cannot vote either.
        $absentMember = $this->userWithRole('R04');
        $committee->members()->create(['user_id' => $absentMember->id]);
        $meeting->attendees()->create(['user_id' => $absentMember->id, 'attended' => false]);

        $this->actingAs($absentMember, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertStatus(422);
    }

    public function test_meeting_cannot_be_deleted_once_a_decision_is_recorded(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, , , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'defer'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", ['comment' => 'تأجيل'])
            ->assertCreated();

        $admin = $this->userWithRole('R08');
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/meetings/{$meeting->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);
    }

    /** @return array{0: User, 1: User, 2: Committee, 3: Meeting, 4: MeetingTransaction} */
    private function committeeMeetingWithAgendaItem(): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');

        $committee = Committee::create(['name_ar' => 'لجنة المشتريات']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع اتخاذ القرار',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);
        $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);

        $transaction = $this->transactionAtCommitteeStage();
        $agendaItem = $meeting->agendaItems()->create(['transaction_id' => $transaction->id, 'agenda_order' => 1]);

        return [$head, $member, $committee, $meeting, $agendaItem];
    }

    private function transactionAtCommitteeStage(): Transaction
    {
        return Transaction::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'معاملة معروضة على اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'transaction_type_id' => TransactionType::where('code', 'PROM')->value('id'),
            'status_id' => TransactionStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('order_no', 7)->value('id'),
            'submitted_at' => now(),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
