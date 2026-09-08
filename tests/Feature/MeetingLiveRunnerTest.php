<?php

namespace Tests\Feature;

use App\Models\Committee;
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
use Tests\PassesControlGates;
use Tests\RecordsStructuredDecisions;
use Tests\TestCase;

/**
 * Stage 34 — the live meeting runner: item_state on top of the Stage 21
 * vote/decision machinery, a discussion-notes feed, and the gate stopping a
 * meeting from closing with unresolved agenda items. See the AGENT_NOTES
 * Stage 34 entry for why "complete" for a request item is only ever reached
 * through a recorded decision, never the manual state endpoint.
 */
class MeetingLiveRunnerTest extends TestCase
{
    // Stage 78 — approving a محضر is now [D] Appendix 63's بوابة 3, so every
    // approve here carries Appendix 8's one reviewer-answered check.
    use PassesControlGates;
    use RecordsStructuredDecisions;
    use RefreshDatabase;

    public function test_advancing_item_state_persists_and_stamps_state_changed_at(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , , $meeting, $agendaItem] = $this->committeeMeetingWithRequestItem();

        $response = $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/state", ['item_state' => 'discussion'])
            ->assertOk()
            ->assertJsonPath('data.item_state', 'discussion');

        $this->assertNotNull($response->json('data.state_changed_at'));
        $this->assertSame('discussion', $agendaItem->fresh()->item_state);
        $this->assertNotNull($agendaItem->fresh()->state_changed_at);
    }

    public function test_a_request_item_rejects_a_manual_complete_state(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , , $meeting, $agendaItem] = $this->committeeMeetingWithRequestItem();

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/state", ['item_state' => 'complete'])
            ->assertStatus(422);

        $this->assertSame('presented', $agendaItem->fresh()->item_state);
    }

    public function test_an_administrative_item_reaches_complete_only_through_the_manual_endpoint(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , , $meeting, , $adminItem] = $this->committeeMeetingWithRequestItem(withAdminItem: true);

        $this->assertFalse($adminItem->fresh()->isResolved());

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$adminItem->id}/state", ['item_state' => 'complete'])
            ->assertOk()
            ->assertJsonPath('data.item_state', 'complete');

        $this->assertTrue($adminItem->fresh()->isResolved());
    }

    public function test_recording_a_decision_auto_completes_its_item(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithRequestItem();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'defer'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'defer'])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('defer', ['comment' => 'تأجيل']))
            ->assertCreated();

        $agendaItem->refresh();
        $this->assertSame('complete', $agendaItem->item_state);
        $this->assertTrue($agendaItem->isResolved());

        // Resolved items refuse any further manual state change.
        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/state", ['item_state' => 'discussion'])
            ->assertStatus(422);
    }

    public function test_closing_a_meeting_is_blocked_with_an_unresolved_item_then_succeeds(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, $member, , $meeting, $agendaItem, $adminItem] = $this->committeeMeetingWithRequestItem(withAdminItem: true);

        // Neither item resolved yet.
        $this->actingAs($head, 'sanctum')
            ->putJson("/api/meetings/{$meeting->id}", ['status' => 'completed'])
            ->assertStatus(422);

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$adminItem->id}/state", ['item_state' => 'complete'])
            ->assertOk();

        // Admin item resolved, request item still isn't.
        $this->actingAs($head, 'sanctum')
            ->putJson("/api/meetings/{$meeting->id}", ['status' => 'completed'])
            ->assertStatus(422);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('approve', [
                'signature' => UploadedFile::fake()->image('signature.png', 10, 10),
            ]))
            ->assertCreated();

        // Both agenda items resolved, but the minutes haven't even been
        // generated yet — Stage 36's second, independent close gate.
        $this->actingAs($head, 'sanctum')
            ->putJson("/api/meetings/{$meeting->id}", ['status' => 'completed'])
            ->assertStatus(422);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/generate")
            ->assertOk();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/review", $this->minutesApprovalPayload())
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_signatures');

        // Signed by only one of the two attendees — still not approved.
        $this->actingAs($head, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/minutes/sign", [
                'signature' => UploadedFile::fake()->image('signature.png', 10, 10),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_signatures');
        $this->actingAs($head, 'sanctum')
            ->putJson("/api/meetings/{$meeting->id}", ['status' => 'completed'])
            ->assertStatus(422);

        // Both attendees signed — minutes auto-approve, and the meeting can close.
        $this->actingAs($member, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/minutes/sign", [
                'signature' => UploadedFile::fake()->image('signature.png', 10, 10),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->actingAs($head, 'sanctum')
            ->putJson("/api/meetings/{$meeting->id}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_a_meeting_with_no_agenda_items_closes_freely(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');
        // Stage 78 — [D] Appendix 8's إثبات صحة الانعقاد is now checked before
        // a محضر may be approved, and Stage 73 stopped the system from
        // inventing a quorum for a committee whose قرار التشكيل was never
        // transcribed, so a fixture meant to produce an approvable محضر has to
        // record one and mark the sitting attended.
        $committee = Committee::create([
            'name_ar' => 'لجنة بلا جدول أعمال',
            'quorum_type' => 'fraction',
            'quorum_numerator' => 1,
            'quorum_denominator' => 2,
            'quorum_comparator' => 'more_than',
            'quorum_text' => 'أكثر من نصف الأعضاء',
        ]);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع بلا بنود',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);

        // Stage 78 — the محضر now needs a recorded, quorate sitting behind it
        // ([D] Appendix 8), so the head attends and signs before it is approved.
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);

        // No agenda items: the close gate still holds until the minutes exist,
        // are reviewed and are signed.
        $this->actingAs($head, 'sanctum')
            ->putJson("/api/meetings/{$meeting->id}", ['status' => 'completed'])
            ->assertStatus(422);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/generate")
            ->assertOk();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/review", $this->minutesApprovalPayload())
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_signatures');
        $this->actingAs($head, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/minutes/sign", ['signature' => UploadedFile::fake()->image('s.png', 10, 10)])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->actingAs($head, 'sanctum')
            ->putJson("/api/meetings/{$meeting->id}", ['status' => 'completed'])
            ->assertOk();
    }

    public function test_discussion_notes_are_listed_and_created_scoped_to_the_agenda_item(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithRequestItem();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/notes", ['note' => 'الملف يحتاج توضيحاً إضافياً'])
            ->assertCreated()
            ->assertJsonPath('data.note', 'الملف يحتاج توضيحاً إضافياً')
            ->assertJsonPath('data.created_by.id', $member->id);

        $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/notes")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_only_the_chair_can_advance_item_state_a_member_can_still_post_notes(): void
    {
        $this->seed(DatabaseSeeder::class);
        [, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithRequestItem();

        $this->actingAs($member, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/state", ['item_state' => 'discussion'])
            ->assertStatus(403);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/notes", ['note' => 'ملاحظة من عضو'])
            ->assertCreated();
    }

    /** @return array{0: User, 1: User, 2: Committee, 3: Meeting, 4: MeetingRequest, 5: ?MeetingRequest} */
    private function committeeMeetingWithRequestItem(bool $withAdminItem = false): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');

        // Stage 78 — [D] Appendix 8's إثبات صحة الانعقاد is now checked before
        // a محضر may be approved, and Stage 73 stopped the system from
        // inventing a quorum for a committee whose قرار التشكيل was never
        // transcribed, so a fixture meant to produce an approvable محضر has to
        // record one and mark the sitting attended.
        $committee = Committee::create([
            'name_ar' => 'لجنة مباشرة الاجتماع',
            'quorum_type' => 'fraction',
            'quorum_numerator' => 1,
            'quorum_denominator' => 2,
            'quorum_comparator' => 'more_than',
            'quorum_text' => 'أكثر من نصف الأعضاء',
        ]);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع مباشر',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);
        $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);

        $requestRecord = $this->requestAtCommitteeStage();
        $agendaItem = $meeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]);

        $adminItem = null;
        if ($withAdminItem) {
            $adminItem = $meeting->agendaItems()->create([
                'item_type' => 'administrative',
                'subject' => 'بند إداري',
                'agenda_order' => 2,
            ]);
        }

        return [$head, $member, $committee, $meeting, $agendaItem, $adminItem];
    }

    private function requestAtCommitteeStage(): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب معروض على اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'submitted_at' => now(),
            // Stage 78 — [D] Appendix 8's "تحديد جهة الاعتماد التالية" is read
            // per agenda item from Stage 54's Art. 45 test, which every request
            // registered after that stage carries. A fixture that skips the
            // pipeline has to supply it, or the محضر cannot be approved.
            'jurisdiction_test' => [
                'has_legal_basis' => true,
                'employee_covered' => true,
                'within_municipal_jurisdiction' => true,
                'committee_decides' => true,
                'final_approval_authority' => 'عميد البلدية',
                'requires_central_approval' => false,
            ],
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
