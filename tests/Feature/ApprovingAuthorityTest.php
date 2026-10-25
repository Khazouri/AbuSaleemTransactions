<?php

namespace Tests\Feature;

use App\Models\Request;
use App\Models\Role;
use App\Models\User;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\HoldsPaperMeetings;
use Tests\TestCase;

/**
 * 2026-10-17 — جهة الاعتماد is العميد or الوزير, one step, chosen per request.
 * Since 2026-10-18 the مقرر enters that choice with the paper sitting's
 * results. The grade threshold only suggests.
 */
class ApprovingAuthorityTest extends TestCase
{
    use HoldsPaperMeetings;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_a_minister_file_goes_to_the_minister_alone_then_to_final(): void
    {
        [$rapporteur, $meeting, [$item]] = $this->paperMeeting();

        $this->recordResults($rapporteur, $meeting, [
            $item->id => ['recommendation' => 'approve', 'approving_authority' => 'minister'],
        ])->assertOk();

        $requestRecord = $item->request->fresh();
        $this->assertSame('local_governance_ministry', $requestRecord->currentStage->code);
        $this->assertSame('awaiting_central_approval', $requestRecord->status->code);
        $this->assertSame('minister', $requestRecord->approving_authority);

        $this->approveAs('R06', $requestRecord);
        // 2026-10-25 — the authority's approve is final once the مقرر confirms it.
        app(WorkflowService::class)->transition($requestRecord->fresh(), 'confirm_decision', $rapporteur);

        $requestRecord->refresh();
        $this->assertSame('final_approval_archiving', $requestRecord->currentStage->code);
        $this->assertSame('final_approved', $requestRecord->status->code);
    }

    public function test_a_dean_file_goes_to_the_dean_alone_and_never_to_the_ministry(): void
    {
        [$rapporteur, $meeting, [$item]] = $this->paperMeeting();

        $this->recordResults($rapporteur, $meeting, [
            $item->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
        ])->assertOk();

        $requestRecord = $item->request->fresh();
        $this->assertSame('approval_by_authority', $requestRecord->currentStage->code);
        $this->assertSame('awaiting_municipal_approval', $requestRecord->status->code);

        // مدير إدارة الشؤون الإدارية no longer approves, so no rule shows him
        // the file at all.
        $this->actingAs($this->userWith('R05'), 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", ['action' => 'approve'])
            ->assertNotFound();

        $this->approveAs('R07', $requestRecord);
        // 2026-10-25 — the authority's approve is final once the مقرر confirms it.
        app(WorkflowService::class)->transition($requestRecord->fresh(), 'confirm_decision', $rapporteur);

        $requestRecord->refresh();
        $this->assertSame('final_approval_archiving', $requestRecord->currentStage->code);
        $this->assertSame('final_approved', $requestRecord->status->code);
        $this->assertFalse($requestRecord->stageLogs()
            ->whereHas('toStage', fn ($q) => $q->where('code', 'local_governance_ministry'))->exists());
    }

    /** 2026-10-18 — a refusal is a recommendation to the authority too, so it names one. */
    public function test_an_approval_and_a_refusal_must_both_name_their_authority(): void
    {
        [$rapporteur, $meeting, [$item]] = $this->paperMeeting();

        $this->recordResults($rapporteur, $meeting, [
            $item->id => ['recommendation' => 'approve'],
        ])->assertStatus(422);
        $this->recordResults($rapporteur, $meeting, [
            $item->id => ['recommendation' => 'decline', 'note' => 'سبب'],
        ])->assertStatus(422);
        $this->assertSame('receive_from_committee', $item->request->fresh()->currentStage->code);

        $this->recordResults($rapporteur, $meeting, [
            $item->id => ['recommendation' => 'decline', 'note' => 'سبب', 'approving_authority' => 'minister'],
        ])->assertOk();
        $this->assertSame('minister', $item->request->fresh()->approving_authority);
        $this->assertSame('local_governance_ministry', $item->request->fresh()->currentStage->code);
    }

    public function test_the_grade_threshold_only_suggests_the_authority(): void
    {
        [$rapporteur, $meeting, [$high, $low]] = $this->paperMeeting(2);
        $high->request->update(['decision_grade' => 12]);

        $suggested = collect($this->actingAs($rapporteur, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}")
            ->assertOk()->json('data.agenda_items'))
            ->mapWithKeys(fn ($row) => [$row['id'] => $row['request']['suggested_authority']]);
        $this->assertSame('minister', $suggested[$high->id]);
        $this->assertSame('dean', $suggested[$low->id]);

        // The مقرر may still send an above-threshold file to the dean.
        $this->recordResults($rapporteur, $meeting, [
            $high->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
            $low->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
        ])->assertOk();
        $this->assertSame('approval_by_authority', $high->request->fresh()->currentStage->code);
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

    /**
     * 2026-10-18 — the authority decides from the meeting page
     * (AuthorityDecisionTest); the workflow move itself is what this suite pins.
     */
    private function approveAs(string $roleCode, Request $requestRecord): void
    {
        app(WorkflowService::class)->transition($requestRecord->fresh(), 'approve', $this->userWith($roleCode));
    }
}
