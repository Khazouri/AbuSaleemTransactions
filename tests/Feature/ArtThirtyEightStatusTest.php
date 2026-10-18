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
     *
     * 2026-10-17 — 15 and 16 are now the two جهات الاعتماد, not two steps:
     * a file the chair gives to الوزير arrives on 16 directly.
     */
    public function test_the_approval_tail_walks_art_38_codes_16_then_17_for_the_minister(): void
    {
        $workflow = app(WorkflowService::class);
        $requestRecord = $this->requestAt('receive_from_committee', 'in_meeting', decisionGrade: 10);
        $requestRecord->update(['approving_authority' => 'minister']);

        $requestRecord = $workflow->transition($requestRecord, 'approve', $this->userWithRole('R02'));
        $this->assertSame('local_governance_ministry', $requestRecord->currentStage->code);
        $this->assertSame('awaiting_central_approval', $requestRecord->status->code);

        $requestRecord = $workflow->transition($requestRecord, 'approve', $this->userWithRole('R06'));
        $this->assertSame('final_approval_archiving', $requestRecord->currentStage->code);
        $this->assertSame('final_approved', $requestRecord->status->code);
    }

    /**
     * Appendix 5's status-12 rule — "لا تنتقل مباشرة إلى التنفيذ" — holds on
     * the dean's path too (2026-10-17). Since 2026-10-18 there is no
     * execution leg at all: code 17 is where the file waits for its archives.
     */
    public function test_the_dean_path_ends_on_code_17_with_no_execution_step(): void
    {
        $workflow = app(WorkflowService::class);
        $requestRecord = $this->requestAt('receive_from_committee', 'in_meeting', decisionGrade: 1);

        $requestRecord = $workflow->transition($requestRecord, 'approve', $this->userWithRole('R02'));
        $this->assertSame('awaiting_municipal_approval', $requestRecord->status->code);

        $dean = $this->userWithRole('R07');
        $requestRecord = $workflow->transition($requestRecord, 'approve', $dean);
        $this->assertSame('final_approval_archiving', $requestRecord->currentStage->code);
        $this->assertSame('final_approved', $requestRecord->status->code);
        $this->assertNotContains('approve', $workflow->availableActions($requestRecord->fresh(), $dean)->all());
    }

    /**
     * Art. 38 code 13 (غير موافق عليها). Since 2026-10-18 the committee only
     * recommends: its refusal goes to جهة الاعتماد (code 15), and the
     * authority's own refusal settles code 13. Art. 91 still refuses an
     * unreasoned committee refusal, hence the required comment.
     */
    public function test_a_committee_non_approval_goes_to_the_authority_who_settles_code_13(): void
    {
        $workflow = app(WorkflowService::class);
        $rapporteur = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('receive_from_committee', 'in_meeting');

        try {
            $workflow->transition($requestRecord, 'reject_by_committee', $rapporteur);
            $this->fail('A non-approval with no stated reason should be refused.');
        } catch (WorkflowTransitionException) {
            // Art. 91 — expected.
        }

        $requestRecord = $workflow->transition($requestRecord, 'reject_by_committee', $rapporteur, 'لا يستوفي شروط الترقية.');
        $this->assertSame('approval_by_authority', $requestRecord->currentStage->code);
        $this->assertSame('awaiting_municipal_approval', $requestRecord->status->code);
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $requestRecord->id,
            'reason' => 'لا يستوفي شروط الترقية.',
        ]);
        // No Approval ledger row: an exception row is not an approval.
        $this->assertDatabaseCount('approvals', 0);

        $requestRecord = $workflow->transition($requestRecord, 'reject', $this->userWithRole('R07'));
        $this->assertSame('final_approval_archiving', $requestRecord->currentStage->code);
        $this->assertSame('not_approved', $requestRecord->status->code);
    }

    /** Code 13 concludes the matter, exactly as `cancelled` already did. */
    public function test_code_13_is_terminal_for_every_copy_of_the_terminal_list(): void
    {
        $workflow = app(WorkflowService::class);
        $dean = $this->userWithRole('R07');
        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');

        $requestRecord = $workflow->transition($requestRecord, 'reject', $dean, 'قرار مسبب.');

        $this->assertSame([], $workflow->availableTransitions($requestRecord->fresh(), $dean)->all());

        // ApprovalController's own copy of the list, reached over HTTP. The
        // dean's checkpoint keeps the admin-manager slug (2026-10-17).
        $this->actingAs($dean, 'sanctum')
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
