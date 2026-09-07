<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\Committee;
use App\Models\Decision;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\ClosesRequests;
use Tests\ExecutesRequests;
use Tests\TestCase;

/** Stage 37 — meeting decisions followed through approval, execution, and close. */
class MeetingOutputsTest extends TestCase
{
    use ClosesRequests;
    use ExecutesRequests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_tracker_scopes_request_outputs_to_one_meeting_with_live_downstream_context(): void
    {
        [$head, $member, $employee, $meeting, $agendaItem] = $this->decidedMeetingOutput('approval_by_authority', 'decided');

        // Administrative/emerging items still count toward the meeting total,
        // but have no request lifecycle and therefore are not output rows.
        $meeting->agendaItems()->create([
            'agenda_order' => 2,
            'item_type' => 'administrative',
            'subject' => 'بند إداري',
            'department_id' => Department::where('code', 'ADM')->value('id'),
        ]);

        $response = $this->actingAs($member, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/outputs")
            ->assertOk()
            ->assertJsonPath('data.meeting.id', $meeting->id)
            ->assertJsonPath('data.summary.total_items', 2)
            ->assertJsonPath('data.summary.decisions', 1)
            ->assertJsonPath('data.summary.advanced', 1)
            ->assertJsonPath('data.summary.awaiting_action', 0)
            ->assertJsonCount(1, 'data.outputs')
            ->assertJsonPath('data.outputs.0.agenda_item_id', $agendaItem->id)
            ->assertJsonPath('data.outputs.0.request.employee.name', $employee->name)
            ->assertJsonPath('data.outputs.0.decision.outcome', 'approve')
            ->assertJsonPath('data.outputs.0.next_action.code', 'approval_by_authority')
            ->assertJsonPath('data.outputs.0.responsible_body.code', 'R05')
            ->assertJsonPath('data.outputs.0.execution_status.code', 'decided')
            ->assertJsonPath('data.outputs.0.can_mark_executed', false);

        $this->assertSame($head->id, $response->json('data.outputs.0.decision.decided_by.id'));
    }

    public function test_final_approval_enters_execution_then_the_head_closes_it_without_moving_stage(): void
    {
        [$head, $member, , $meeting, $agendaItem, $requestRecord] = $this->decidedMeetingOutput('final_approval_archiving', 'final_approved');
        $finalApprover = $this->userWithRole('R07');

        $this->actingAs($finalApprover, 'sanctum')
            ->post("/api/approvals/final/{$requestRecord->id}", [
                'signature' => UploadedFile::fake()->image('final.png', 10, 10),
            ])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'in_execution');

        $this->assertSame('final_approval_archiving', $requestRecord->fresh()->currentStage->code);
        // Stage 57 removed the competent_authority checkpoint, so
        // final_approval_archiving's self-loop is level 5 now, not 6.
        $this->assertDatabaseHas('approvals', [
            'request_id' => $requestRecord->id,
            'level' => 5,
            'approved_by_user_id' => $finalApprover->id,
        ]);

        // The self-loop is no longer pending once it has handed work to execution.
        $this->actingAs($finalApprover, 'sanctum')
            ->getJson('/api/approvals/final')
            ->assertOk()
            ->assertJsonMissing(['id' => $requestRecord->id]);

        $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/outputs")
            ->assertOk()
            ->assertJsonPath('data.summary.in_execution', 1)
            ->assertJsonPath('data.outputs.0.next_action.code', 'mark_executed')
            ->assertJsonPath('data.outputs.0.responsible_body.code', 'ADM')
            ->assertJsonPath('data.outputs.0.can_mark_executed', true)
            ->assertJsonPath('data.outputs.0.can_close', false);

        // Members may monitor outputs, but only the head's edit grant can act.
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/outputs/{$agendaItem->id}/execute", $this->executionPayload($requestRecord))
            ->assertForbidden();

        // Stage 69 — Appendix 5 refuses to let 19 and 20 collapse, so closing
        // straight out of `in_execution` is not a legal move. Stage 75 moved
        // the close itself onto the request (Art. 37's other two final paths
        // close requests that never reached an agenda), so the refusal now
        // comes from RequestClosureService and reads as Appendix 48's own
        // "لا يجوز إقفال معاملة تحت التنفيذ".
        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertStatus(422);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/outputs/{$agendaItem->id}/execute", $this->executionPayload($requestRecord))
            ->assertOk()
            ->assertJsonPath('data.summary.in_execution', 0)
            ->assertJsonPath('data.summary.executed', 1)
            ->assertJsonPath('data.summary.completed_closed', 0)
            ->assertJsonPath('data.outputs.0.execution_status.code', 'executed')
            ->assertJsonPath('data.outputs.0.next_action.code', 'close_request')
            ->assertJsonPath('data.outputs.0.can_mark_executed', false)
            ->assertJsonPath('data.outputs.0.can_close', true);

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertOk()
            ->assertJsonPath('data.status.code', 'completed_closed');

        $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/outputs")
            ->assertOk()
            ->assertJsonPath('data.summary.executed', 0)
            ->assertJsonPath('data.summary.completed_closed', 1)
            ->assertJsonPath('data.outputs.0.execution_status.code', 'completed_closed')
            ->assertJsonPath('data.outputs.0.next_action', null)
            ->assertJsonPath('data.outputs.0.can_mark_executed', false)
            ->assertJsonPath('data.outputs.0.can_close', false);

        $closed = $requestRecord->fresh();
        $this->assertSame('final_approval_archiving', $closed->currentStage->code);
        $this->assertSame('completed_closed', $closed->status->code);
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $requestRecord->id,
            'from_status_id' => RequestStatus::where('code', 'in_execution')->value('id'),
            'to_status_id' => RequestStatus::where('code', 'executed')->value('id'),
            'changed_by_user_id' => $head->id,
        ]);
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $requestRecord->id,
            'from_status_id' => RequestStatus::where('code', 'executed')->value('id'),
            'to_status_id' => RequestStatus::where('code', 'completed_closed')->value('id'),
            'changed_by_user_id' => $head->id,
        ]);

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertStatus(422);
    }

    /** Stage 59, Track J — [D] Arts. 34–37: an open appeal keeps the file open. */
    public function test_completion_is_blocked_while_an_open_appeal_exists_then_succeeds_once_it_closes(): void
    {
        [$head, , $employee, $meeting, $agendaItem, $requestRecord] = $this->decidedMeetingOutput('final_approval_archiving', 'in_execution');

        $appeal = Appeal::create([
            'appellant_user_id' => $employee->id,
            'original_request_id' => $requestRecord->id,
            'original_decision_reference' => 'قرار تنفيذ',
            'known_at' => now()->subDay(),
            'appeal_reasons' => 'اعتراض على أسلوب التنفيذ.',
            'final_request' => 'وقف التنفيذ ومراجعة القرار.',
            'appeal_status_id' => AppealStatus::where('code', 'submitted')->value('id'),
        ]);

        // Stage 69 — recording that the effect was carried out is NOT held up
        // by an open appeal: Arts. 34-37 keep the *file* open, which is a
        // statement about closure. So execution succeeds and the close is what
        // the hold refuses.
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/outputs/{$agendaItem->id}/execute", $this->executionPayload($requestRecord))
            ->assertOk()
            ->assertJsonPath('data.outputs.0.execution_status.code', 'executed');

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertStatus(422);

        $this->assertSame('executed', $requestRecord->fresh()->status->code);

        $appeal->update(['appeal_status_id' => AppealStatus::where('code', 'notified_closed')->value('id')]);

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertOk()
            ->assertJsonPath('data.status.code', 'completed_closed');
    }

    public function test_completion_requires_the_output_to_belong_to_the_selected_meeting(): void
    {
        [$head, , , $meeting, $agendaItem] = $this->decidedMeetingOutput('final_approval_archiving', 'in_execution');
        $otherCommittee = Committee::create(['name_ar' => 'لجنة أخرى']);
        $otherMeeting = Meeting::create([
            'committee_id' => $otherCommittee->id,
            'title' => 'اجتماع آخر',
            'scheduled_at' => now(),
            'created_by_user_id' => $head->id,
        ]);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$otherMeeting->id}/outputs/{$agendaItem->id}/execute", $this->executionPayload($agendaItem->request))
            ->assertNotFound();

        $this->assertSame('in_execution', $agendaItem->request->fresh()->status->code);
    }

    public function test_completion_rejects_a_request_that_has_not_entered_execution(): void
    {
        [$head, , , $meeting, $agendaItem, $requestRecord] = $this->decidedMeetingOutput('final_approval_archiving', 'final_approved');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/outputs/{$agendaItem->id}/execute", $this->executionPayload($requestRecord))
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertStatus(422);

        $this->assertSame('final_approved', $requestRecord->fresh()->status->code);
        $this->assertDatabaseCount('request_status_history', 0);
    }

    /** @return array{0: User, 1: User, 2: User, 3: Meeting, 4: MeetingRequest, 5: Request} */
    private function decidedMeetingOutput(string $stageCode, string $statusCode): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');
        $employee = $this->userWithRole('R01');

        $committee = Committee::create(['name_ar' => 'لجنة متابعة المخرجات']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);
        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'meeting_number' => '13/2026',
            'title' => 'اجتماع متابعة التنفيذ',
            'scheduled_at' => now(),
            'created_by_user_id' => $head->id,
        ]);

        $requestRecord = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب موظف للمتابعة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $employee->id,
            'submitted_at' => now(),
        ]);
        $agendaItem = $meeting->agendaItems()->create([
            'request_id' => $requestRecord->id,
            'agenda_order' => 1,
            'item_state' => 'complete',
        ]);
        Decision::create([
            'meeting_request_id' => $agendaItem->id,
            'outcome' => 'approve',
            'votes_approve_count' => 2,
            'comment' => 'اعتمدت اللجنة الطلب.',
            'decided_by_user_id' => $head->id,
            'decided_at' => now(),
        ]);

        return [$head, $member, $employee, $meeting, $agendaItem, $requestRecord];
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
