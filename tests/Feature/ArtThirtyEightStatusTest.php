<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\AppealEligibility;
use App\Services\ReportMetricsService;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 69, Track K — the status vocabulary IS [D] Art. 38's twenty-code
 * dictionary.
 *
 * Covers the two splits this stage made that no other suite exercises: the
 * approval tail's 15/16 pair (Art. 31 names 16 verbatim) and the committee's
 * own non-approval, code 13, which until now was indistinguishable in the data
 * from a plain administrative withdrawal. The 19/20 split lives in
 * MeetingOutputsTest alongside the rest of the execution loop, and Appendix 5's
 * re-review rule in RequestLegalReviewTest.
 */
class ArtThirtyEightStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Art. 38: 15 (بانتظار اعتماد البلدية) → 16 (بانتظار الاعتماد المركزي) →
     * 17 (معتمدة نهائيًا). Art. 31 gives 16 in so many words: "وفي هذه الحالة
     * تصبح حالة المعاملة: (بانتظار الاعتماد المركزي)".
     */
    public function test_the_approval_tail_walks_art_38_codes_15_then_16_then_17(): void
    {
        $workflow = app(WorkflowService::class);
        // decision_grade 10 is at the seeded PROM threshold, so this request
        // takes the ministry path rather than Stage 57's bypass.
        $requestRecord = $this->requestAt('receive_from_committee', 'in_meeting', decisionGrade: 10);

        $requestRecord = $workflow->transition($requestRecord, 'approve', $this->userWithRole('R03'), signaturePath: 'signatures/a.png');
        $this->assertSame('approval_by_authority', $requestRecord->currentStage->code);
        $this->assertSame('awaiting_municipal_approval', $requestRecord->status->code);

        $requestRecord = $workflow->transition($requestRecord, 'approve', $this->userWithRole('R05'), signaturePath: 'signatures/b.png');
        $this->assertSame('local_governance_ministry', $requestRecord->currentStage->code);
        $this->assertSame('awaiting_central_approval', $requestRecord->status->code);

        $requestRecord = $workflow->transition($requestRecord, 'approve', $this->userWithRole('R06'), signaturePath: 'signatures/c.png');
        $this->assertSame('final_approval_archiving', $requestRecord->currentStage->code);
        $this->assertSame('final_approved', $requestRecord->status->code);
    }

    /**
     * Appendix 5's status-12 rule — "لا تنتقل مباشرة إلى التنفيذ" — holds on
     * Stage 57's low-grade bypass too: it skips the *central* approval, never
     * the final one, so execution is still reached only through code 17.
     */
    public function test_the_ministry_bypass_still_reaches_execution_only_through_code_17(): void
    {
        $workflow = app(WorkflowService::class);
        $requestRecord = $this->requestAt('receive_from_committee', 'in_meeting', decisionGrade: 1);

        $requestRecord = $workflow->transition($requestRecord, 'approve', $this->userWithRole('R03'), signaturePath: 'signatures/a.png');
        $this->assertSame('awaiting_municipal_approval', $requestRecord->status->code);

        $requestRecord = $workflow->transition($requestRecord, 'approve', $this->userWithRole('R05'), signaturePath: 'signatures/b.png');
        $this->assertSame('final_approval_archiving', $requestRecord->currentStage->code);
        $this->assertSame('final_approved', $requestRecord->status->code);

        $requestRecord = $workflow->transition($requestRecord, 'approve', $this->userWithRole('R07'), signaturePath: 'signatures/d.png');
        $this->assertSame('in_execution', $requestRecord->status->code);
    }

    /**
     * Art. 38 code 13 (غير موافق عليها) is the committee's own non-approval.
     * Art. 91 refuses an unreasoned refusal, hence the required comment.
     */
    public function test_a_committee_non_approval_lands_on_code_13_and_requires_a_reason(): void
    {
        $workflow = app(WorkflowService::class);
        $head = $this->userWithRole('R03');
        $requestRecord = $this->requestAt('receive_from_committee', 'in_meeting');

        try {
            $workflow->transition($requestRecord, 'reject_by_committee', $head);
            $this->fail('A non-approval with no stated reason should be refused.');
        } catch (WorkflowTransitionException) {
            // Art. 91 — expected.
        }

        $requestRecord = $workflow->transition($requestRecord, 'reject_by_committee', $head, 'لا يستوفي شروط الترقية.');

        // Self-loop: the committee is where the matter ended, so the stage
        // stays put and only the status records the outcome.
        $this->assertSame('receive_from_committee', $requestRecord->currentStage->code);
        $this->assertSame('not_approved', $requestRecord->status->code);
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $requestRecord->id,
            'to_status_id' => RequestStatus::where('code', 'not_approved')->value('id'),
            'reason' => 'لا يستوفي شروط الترقية.',
        ]);

        // No Approval ledger row: an exception row is not an approval.
        $this->assertDatabaseCount('approvals', 0);
    }

    /** Code 13 concludes the matter, exactly as `cancelled` already did. */
    public function test_code_13_is_terminal_for_every_copy_of_the_terminal_list(): void
    {
        $workflow = app(WorkflowService::class);
        $head = $this->userWithRole('R03');
        $requestRecord = $this->requestAt('receive_from_committee', 'in_meeting');

        $requestRecord = $workflow->transition($requestRecord, 'reject_by_committee', $head, 'قرار مسبب.');

        $this->assertSame([], $workflow->availableTransitions($requestRecord->fresh(), $head)->all());

        // ApprovalController's own copy of the list, reached over HTTP.
        $this->actingAs($this->userWithRole('R05'), 'sanctum')
            ->getJson('/api/approvals/admin-manager')
            ->assertOk()
            ->assertJsonMissing(['id' => $requestRecord->id]);
    }

    /**
     * The point of splitting 13 out of `cancelled`: an appeal target is now
     * decided by the status itself, not by digging for a linked
     * Decision(reject) to tell a committee refusal from a withdrawal.
     */
    public function test_code_13_is_appealable_while_a_plain_cancellation_is_not(): void
    {
        $eligibility = app(AppealEligibility::class);
        $employee = $this->userWithRole('R01');

        $refused = $this->requestAt('receive_from_committee', 'not_approved', creator: $employee);
        $withdrawn = $this->requestAt('receive_from_committee', 'cancelled', creator: $employee);

        $this->assertTrue($eligibility->requestReachedResult($refused->fresh()));
        $this->assertFalse($eligibility->requestReachedResult($withdrawn->fresh()));

        $this->assertNull($eligibility->reasonBlockingAppeal($refused->fresh(), $employee, null));
        $this->assertNotNull($eligibility->reasonBlockingAppeal($withdrawn->fresh(), $employee, null));
    }

    /** Art. 38 code 19 (منفذة) is a result too — appealable, and "completed". */
    public function test_code_19_counts_as_a_reached_result(): void
    {
        $employee = $this->userWithRole('R01');
        $executed = $this->requestAt('final_approval_archiving', 'executed', creator: $employee);

        $this->assertTrue(app(AppealEligibility::class)->requestReachedResult($executed->fresh()));
        $this->assertContains('executed', ReportMetricsService::COMPLETED_STATUSES);
    }

    /** Every one of Art. 38's twenty codes has a status backing it. */
    public function test_every_art_38_code_has_a_seeded_status(): void
    {
        // Art. 38's dictionary, in order, mapped onto the status code that
        // carries it. Where this system deliberately merges or splits a code,
        // the mapping is documented in RequestStatusSeeder and gap-analysis §4.
        $codes = [
            '01' => 'new',
            '02' => 'routed_to_hr',
            '03' => 'in_meeting',
            '04' => 'in_review',
            '05' => 'incomplete',
            '06' => 'registered',
            '07' => 'under_legal_review',
            '08' => 'ready',
            '09' => 'on_agenda',
            '10' => 'under_discussion',
            '11' => 'deferred',
            '12' => 'decided',
            '13' => 'not_approved',
            '14' => 'outside_jurisdiction',
            '15' => 'awaiting_municipal_approval',
            '16' => 'awaiting_central_approval',
            '17' => 'final_approved',
            '18' => 'in_execution',
            '19' => 'executed',
            '20' => 'completed_closed',
        ];

        $seeded = RequestStatus::query()->pluck('code')->all();

        foreach ($codes as $artCode => $statusCode) {
            $this->assertContains($statusCode, $seeded, "Art. 38 code {$artCode} has no status.");
        }
    }

    private function requestAt(
        string $stageCode,
        string $statusCode,
        ?User $creator = null,
        int $decisionGrade = 10,
    ): Request {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب لاختبار قاموس حالات المادة 38',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $creator?->id,
            'submitted_at' => now(),
            'decision_grade' => $decisionGrade,
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user->fresh();
    }
}
