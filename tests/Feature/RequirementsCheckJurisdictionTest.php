<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\LegalReviewNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\PassesControlGates;
use Tests\TestCase;

/**
 * Stage 54 — [D] Art. 45's 6-question jurisdiction test at requirements_check,
 * gating that stage's approve / declare_no_jurisdiction / reject_formally
 * outcomes, plus [A] §4 stage 3's 4-result outcome set.
 */
class RequirementsCheckJurisdictionTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;

    /** 2026-10-31 — the jurisdiction test and بوابة 1 are one card with one submit. */
    public function test_the_qaid_card_records_the_jurisdiction_test_with_the_file_review(): void
    {
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review');

        $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.jurisdiction_test', null);

        $this->recordQaidCard($reviewer, $requestRecord, $this->answers())
            ->assertOk()
            ->assertJsonPath('data.jurisdiction_test.has_legal_basis', true)
            ->assertJsonPath('data.jurisdiction_test.final_approval_authority', 'عميد البلدية')
            ->assertJsonPath('data.jurisdiction_test.requires_central_approval', false)
            ->assertJsonPath('data.control_gates.intake.jurisdiction_test.recorded_by.id', $reviewer->id)
            ->assertJsonPath('data.control_gates.intake.recorded_by.id', $reviewer->id);

        $requestRecord->refresh();
        $this->assertNotNull($requestRecord->jurisdiction_test);
        $this->assertTrue($requestRecord->intake_gate['facts_verified']);
    }

    public function test_the_separate_jurisdiction_test_route_is_gone(): void
    {
        $this->seed(DatabaseSeeder::class);
        $requestRecord = $this->requestAt('requirements_check', 'in_review');

        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/jurisdiction-test", $this->answers())
            ->assertNotFound();
    }

    public function test_a_partial_answer_set_is_rejected(): void
    {
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review');

        $answers = $this->answers();
        unset($answers['final_approval_authority']);

        $this->recordQaidCard($reviewer, $requestRecord, $answers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('jurisdiction_test.final_approval_authority');

        $requestRecord->refresh();
        $this->assertNull($requestRecord->jurisdiction_test);
        $this->assertNull($requestRecord->intake_gate);
    }

    public function test_approve_declare_no_jurisdiction_and_reject_formally_are_refused_until_the_test_is_recorded(): void
    {
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');

        foreach (['approve', 'declare_no_jurisdiction', 'reject_formally'] as $action) {
            $requestRecord = $this->requestAt('requirements_check', 'in_review');

            $payload = ['action' => $action, 'comment' => 'سبب.'];

            $this->actingAs($reviewer, 'sanctum')
                ->post("/api/requests/{$requestRecord->id}/transition", $payload, ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('action');

            $this->assertSame('requirements_check', $requestRecord->refresh()->currentStage->code);
        }
    }

    public function test_return_missing_docs_and_cancel_are_unaffected_by_the_gate(): void
    {
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review');

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", [
                'action' => 'return_missing_docs',
                'comment' => 'المستند غير مرفق.',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'receive_from_municipality')
            ->assertJsonPath('data.status.code', 'incomplete');
    }

    public function test_the_three_gated_actions_succeed_once_the_test_is_recorded(): void
    {
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');

        // Stage 78 — this hop alone is gated on Appendix 63's بوابة 1:
        // declaring عدم اختصاص or refusing formally does not send the file on
        // to the rapporteur's review, so neither needs the documents complete
        // first. (The gate is no longer "before the قيد" — that moved to the
        // receiving body's own register action; see IntakeGateService.)
        $approved = $this->passIntakeGate(
            $this->requestAt('requirements_check', 'in_review', jurisdictionTest: $this->answers()),
        );
        $this->actingAs($reviewer, 'sanctum')
            ->post("/api/requests/{$approved->id}/transition", [
                'action' => 'approve',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'receive_from_committee')
            // 2026-10-31 — the قيد hands the file to the legal member itself.
            ->assertJsonPath('data.status.code', 'under_legal_review');

        $referred = $this->requestAt('requirements_check', 'in_review', jurisdictionTest: $this->answers());
        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/requests/{$referred->id}/transition", [
                'action' => 'declare_no_jurisdiction',
                'comment' => 'الموضوع خارج اختصاص اللجنة.',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'requirements_check')
            ->assertJsonPath('data.status.code', 'outside_jurisdiction');

        $rejected = $this->requestAt('requirements_check', 'in_review', jurisdictionTest: $this->answers());
        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/requests/{$rejected->id}/transition", [
                'action' => 'reject_formally',
                'comment' => 'الطلب مكرر ولا يستوفي الشروط.',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'requirements_check')
            ->assertJsonPath('data.status.code', 'rejected');
    }

    public function test_available_transitions_excludes_the_gated_actions_until_recorded(): void
    {
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review');

        $before = $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();
        $this->assertFalse(collect($before->json('data.available_actions'))->contains('approve'));
        $this->assertFalse(collect($before->json('data.available_actions'))->contains('declare_no_jurisdiction'));
        $this->assertFalse(collect($before->json('data.available_actions'))->contains('reject_formally'));
        $this->assertTrue(collect($before->json('data.available_actions'))->contains('return_missing_docs'));
        // Decision wizard — each gated action is reported with the reason the
        // endpoint would give, rather than vanishing.
        $blocked = collect($before->json('data.blocked_transitions'))->keyBy('action');
        foreach (['approve', 'declare_no_jurisdiction', 'reject_formally'] as $gated) {
            $this->assertSame('يجب إكمال اختبار الاختصاص (المادة 45) قبل اتخاذ هذا الإجراء.', $blocked[$gated]['reason'] ?? null);
        }
        $this->assertFalse($blocked->has('return_missing_docs'));

        $this->recordQaidCard($reviewer, $requestRecord, $this->answers())->assertOk();
        // Stage 78 — approve additionally needs Appendix 63's بوابة 1, which the
        // preview filter reads from the same predicate the endpoint does.
        $this->passIntakeGate($requestRecord);

        $after = $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();
        $this->assertTrue(collect($after->json('data.available_actions'))->contains('approve'));
        $this->assertTrue(collect($after->json('data.available_actions'))->contains('declare_no_jurisdiction'));
        $this->assertTrue(collect($after->json('data.available_actions'))->contains('reject_formally'));
        $this->assertSame([], $after->json('data.blocked_transitions'));
    }

    public function test_the_reviewer_approval_queue_is_also_gated(): void
    {
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review');

        $this->actingAs($reviewer, 'sanctum')
            ->post("/api/approvals/reviewer/{$requestRecord->id}", [], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');

        $this->assertSame('requirements_check', $requestRecord->refresh()->currentStage->code);
    }

    /**
     * 2026-10-31 — the قيد sends the file to the legal member, who hears of it,
     * unless Settings lets the مقرر skip the review: then it stays on the
     * pending list, as it did before.
     */
    public function test_the_qaid_sends_the_file_to_the_legal_member(): void
    {
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');
        $legal = $this->userWithRole('R11');
        Notification::fake();

        $file = $this->passIntakeGate($this->requestAt('requirements_check', 'in_review', jurisdictionTest: $this->answers()));
        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/requests/{$file->id}/transition", ['action' => 'approve'])
            ->assertOk();

        $this->assertSame(['registered', 'under_legal_review'], $file->statusHistory()
            ->orderBy('id')->with('toStatus')->get()->pluck('toStatus.code')->all());
        Notification::assertSentTo($legal, LegalReviewNotification::class);

        Setting::where('key', Setting::LEGAL_REVIEW_BYPASS)->update(['value' => '1']);
        $skipped = $this->passIntakeGate($this->requestAt('requirements_check', 'in_review', jurisdictionTest: $this->answers()));
        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/requests/{$skipped->id}/transition", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status.code', 'registered');
    }

    private function recordQaidCard(User $reviewer, Request $requestRecord, array $answers): TestResponse
    {
        return $this->actingAs($reviewer, 'sanctum')->patchJson("/api/requests/{$requestRecord->id}/intake-gate", [
            'documents' => [],
            'facts_verified' => true,
            'jurisdiction_test' => $answers,
        ]);
    }

    private function answers(): array
    {
        return [
            'has_legal_basis' => true,
            'employee_covered' => true,
            'within_municipal_jurisdiction' => true,
            'committee_decides' => true,
            'final_approval_authority' => 'عميد البلدية',
            'requires_central_approval' => false,
        ];
    }

    private function requestAt(
        string $stageCode,
        string $statusCode,
        ?array $jurisdictionTest = null,
    ): Request {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'اختبار الاختصاص',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'submitted_at' => now(),
            'decision_grade' => 10,
            'jurisdiction_test' => $jurisdictionTest,
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
