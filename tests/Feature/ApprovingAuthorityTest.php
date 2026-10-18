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
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\RecordsStructuredDecisions;
use Tests\RunsStudySequence;
use Tests\TestCase;

/**
 * 2026-10-17 — جهة الاعتماد is العميد or الوزير, one step, chosen by the chair
 * when recording the item's result. The grade threshold only suggests.
 */
class ApprovingAuthorityTest extends TestCase
{
    use RecordsStructuredDecisions;
    use RefreshDatabase;
    use RunsStudySequence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_minister_file_goes_to_the_minister_alone_then_to_final(): void
    {
        [$head, $meeting, $item] = $this->decidedByVote('approve');

        $this->actingAs($head, 'sanctum')
            ->postJson($this->decisionUrl($meeting, $item), $this->decisionPayload('approve', ['approving_authority' => 'minister']))
            ->assertCreated();

        $requestRecord = $item->request->fresh();
        $this->assertSame('local_governance_ministry', $requestRecord->currentStage->code);
        $this->assertSame('awaiting_central_approval', $requestRecord->status->code);
        $this->assertSame('minister', $requestRecord->approving_authority);

        $this->approveAs('R06', $requestRecord);

        $requestRecord->refresh();
        $this->assertSame('final_approval_archiving', $requestRecord->currentStage->code);
        $this->assertSame('final_approved', $requestRecord->status->code);
    }

    public function test_a_dean_file_goes_to_the_dean_alone_and_never_to_the_ministry(): void
    {
        [$head, $meeting, $item] = $this->decidedByVote('approve');

        $this->actingAs($head, 'sanctum')
            ->postJson($this->decisionUrl($meeting, $item), $this->decisionPayload('approve', ['approving_authority' => 'dean']))
            ->assertCreated();

        $requestRecord = $item->request->fresh();
        $this->assertSame('approval_by_authority', $requestRecord->currentStage->code);
        $this->assertSame('awaiting_municipal_approval', $requestRecord->status->code);

        // مدير إدارة الشؤون الإدارية no longer approves, so no rule shows him
        // the file at all.
        $this->actingAs($this->userWith('R05'), 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", ['action' => 'approve'])
            ->assertNotFound();

        $this->approveAs('R07', $requestRecord);

        $requestRecord->refresh();
        $this->assertSame('final_approval_archiving', $requestRecord->currentStage->code);
        $this->assertSame('final_approved', $requestRecord->status->code);
        $this->assertFalse($requestRecord->stageLogs()
            ->whereHas('toStage', fn ($q) => $q->where('code', 'local_governance_ministry'))->exists());
    }

    public function test_a_conditional_approval_for_the_minister_keeps_its_conditions(): void
    {
        [$head, $meeting, $item] = $this->decidedByVote('conditional_approval');

        $this->actingAs($head, 'sanctum')
            ->postJson($this->decisionUrl($meeting, $item), $this->decisionPayload('conditional_approval', [
                'approving_authority' => 'minister',
                'comment' => 'بشرط استكمال كشف الخدمة',
            ]))
            ->assertCreated();

        $requestRecord = $item->request->fresh();
        $this->assertSame('local_governance_ministry', $requestRecord->currentStage->code);
        $this->assertSame('approved_with_conditions', $requestRecord->status->code);
    }

    /** 2026-10-18 — a refusal is a recommendation to the authority too, so it names one. */
    public function test_an_approval_and_a_refusal_must_both_name_their_authority(): void
    {
        [$head, $meeting, $item] = $this->decidedByVote('approve');

        $this->actingAs($head, 'sanctum')
            ->postJson($this->decisionUrl($meeting, $item), $this->decisionPayload('approve', ['approving_authority' => null]))
            ->assertStatus(422);
        $this->assertSame('receive_from_committee', $item->request->fresh()->currentStage->code);

        [$head, $meeting, $item] = $this->decidedByVote('reject');

        $this->actingAs($head, 'sanctum')
            ->postJson($this->decisionUrl($meeting, $item), $this->decisionPayload('reject', ['comment' => 'سبب']))
            ->assertStatus(422);
        $this->actingAs($head, 'sanctum')
            ->postJson($this->decisionUrl($meeting, $item), $this->decisionPayload('reject', ['comment' => 'سبب', 'approving_authority' => 'minister']))
            ->assertCreated();
        $this->assertSame('minister', $item->request->fresh()->approving_authority);
        $this->assertSame('local_governance_ministry', $item->request->fresh()->currentStage->code);
    }

    public function test_the_grade_threshold_only_suggests_the_authority(): void
    {
        [$head, $meeting, $item] = $this->decidedByVote('approve', decisionGrade: 12);

        $duty = collect($this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/duties")
            ->assertOk()->json('data.available'))->firstWhere('action', 'record_decision');
        $this->assertSame('minister', $duty['suggested_authority']);

        // The chair may still send an above-threshold file to the dean.
        $this->actingAs($head, 'sanctum')
            ->postJson($this->decisionUrl($meeting, $item), $this->decisionPayload('approve', ['approving_authority' => 'dean']))
            ->assertCreated();
        $this->assertSame('approval_by_authority', $item->request->fresh()->currentStage->code);

        [$head, $meeting, $item] = $this->decidedByVote('approve', decisionGrade: 5);
        $duty = collect($this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/duties")
            ->json('data.available'))->firstWhere('action', 'record_decision');
        $this->assertSame('dean', $duty['suggested_authority']);
    }

    public function test_the_admin_names_the_one_minister_account(): void
    {
        $admin = $this->userWith('R08');
        $officeManager = $this->userWith('R01');

        $this->actingAs($this->userWith('R02'), 'sanctum')
            ->putJson('/api/committee-roles/minister', ['user_id' => $officeManager->id])
            ->assertStatus(422);
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/committee-roles/minister', ['user_id' => $officeManager->id])
            ->assertOk();

        $this->assertSame($officeManager->id, User::activeSeatHolder('minister')?->id);
        $this->assertSame(1, Role::where('code', 'R06')->first()->users()->count());
    }

    /** @return array{0: User, 1: Meeting, 2: MeetingRequest} */
    private function decidedByVote(string $vote, int $decisionGrade = 5): array
    {
        $head = $this->userWith('R03');
        $member = $this->userWith('R04');

        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);
        $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);

        $requestRecord = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب معروض على اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'decision_grade' => $decisionGrade,
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'submitted_at' => now(),
        ]);
        $item = $meeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]);
        $this->completeStudySequence($item);

        foreach ([$head, $member] as $voter) {
            $this->actingAs($voter, 'sanctum')
                ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/votes", ['vote' => $vote])
                ->assertCreated();
        }

        return [$head, $meeting, $item];
    }

    /**
     * 2026-10-18 — the authority decides from the meeting page
     * (AuthorityDecisionTest); the workflow move itself is what this suite pins.
     */
    private function approveAs(string $roleCode, Request $requestRecord): void
    {
        app(WorkflowService::class)->transition($requestRecord->fresh(), 'approve', $this->userWith($roleCode));
    }

    private function decisionUrl(Meeting $meeting, MeetingRequest $item): string
    {
        return "/api/meetings/{$meeting->id}/agenda/{$item->id}/decision";
    }

    private function userWith(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
