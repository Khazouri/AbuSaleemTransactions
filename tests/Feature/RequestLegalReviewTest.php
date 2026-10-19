<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PassesControlGates;
use Tests\TestCase;

/**
 * Stage 68 — [D] Art. 21 / [E] stage 08's pre-meeting legal review: the review
 * record itself, Art. 38's status 07, and the agenda-insertion gate. Since
 * 2026-10-19 the legal member records a توصية and جهة الاعتماد only.
 */
class RequestLegalReviewTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;

    private const APPROVE = ['recommendation' => 'approve', 'approving_authority' => 'dean'];

    public function test_the_rapporteur_hands_a_ready_file_to_the_legal_member(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rapporteur = $this->userWithRole('R02');
        $requestRecord = $this->committeeRequest('ready');

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews/request")
            ->assertOk()
            ->assertJsonPath('data.status.code', 'under_legal_review');

        // Status-only: Art. 21's review is agenda preparation inside the
        // committee stage, never a stage of its own.
        $this->assertSame(
            WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            $requestRecord->refresh()->current_stage_id,
        );
        $this->assertSame(0, $requestRecord->stageLogs()->count());
        $this->assertSame(1, $requestRecord->statusHistory()->count());
    }

    public function test_a_file_that_was_never_handed_over_cannot_be_reviewed(): void
    {
        $this->seed(DatabaseSeeder::class);
        $legalOfficer = $this->userWithRole('R11');
        $requestRecord = $this->committeeRequest('ready');

        $this->actingAs($legalOfficer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", self::APPROVE)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recommendation');

        // The status move runs first inside the same transaction, so a refused
        // move must leave no review row behind either.
        $this->assertSame(0, $requestRecord->legalReviews()->count());
    }

    /**
     * 2026-10-19 — the legal member enters a توصية and جهة الاعتماد only, and
     * either توصية returns the file to the pending list: «عدم موافقة» is advice
     * to the committee, not a block.
     */
    public function test_either_recommendation_returns_the_file_to_the_pending_list(): void
    {
        $this->seed(DatabaseSeeder::class);
        $legalOfficer = $this->userWithRole('R11');

        foreach (['approve' => 'dean', 'decline' => 'minister'] as $recommendation => $authority) {
            $requestRecord = $this->committeeRequest('under_legal_review');

            $this->actingAs($legalOfficer, 'sanctum')
                ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                    'recommendation' => $recommendation,
                    'approving_authority' => $authority,
                ])
                ->assertCreated()
                ->assertJsonPath('data.recommendation', $recommendation)
                ->assertJsonPath('data.approving_authority', $authority)
                ->assertJsonPath('data.permits_agenda', true);

            $this->assertSame('ready', $requestRecord->refresh()->status->code, "recommendation {$recommendation}");
        }
    }

    public function test_the_form_takes_a_recommendation_and_an_authority_and_nothing_else(): void
    {
        $this->seed(DatabaseSeeder::class);
        $legalOfficer = $this->userWithRole('R11');
        $requestRecord = $this->committeeRequest('under_legal_review');

        $this->actingAs($legalOfficer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recommendation', 'approving_authority']);

        $this->actingAs($legalOfficer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                'recommendation' => 'sound_ready',
                'approving_authority' => 'عميد البلدية',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recommendation', 'approving_authority']);

        // The old card's fields and verdict are no longer read; the note stays optional.
        $this->actingAs($legalOfficer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                ...self::APPROVE,
                'verdict' => 'needs_document',
                'approving_body' => 'عميد البلدية',
                'legal_note' => 'لا مانع.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.verdict', null)
            ->assertJsonPath('data.approving_body', null)
            ->assertJsonPath('data.legal_note', 'لا مانع.');
    }

    /**
     * An unreviewed file still cannot reach the agenda; any recommendation
     * admits it, while a blocking verdict recorded before 2026-10-19 still holds.
     */
    public function test_the_agenda_gate_admits_any_recommendation(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');
        $legalOfficer = $this->userWithRole('R11');
        $meeting = $this->meetingFor($head);

        $unreviewed = $this->committeeRequest('ready');
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $unreviewed->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request_id');

        foreach (['approve', 'decline'] as $recommendation) {
            $requestRecord = $this->committeeRequest('under_legal_review');
            $this->actingAs($legalOfficer, 'sanctum')
                ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                    'recommendation' => $recommendation,
                    'approving_authority' => 'dean',
                ])->assertCreated();

            $this->actingAs($head, 'sanctum')
                ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestRecord->id])
                ->assertCreated();
        }

        $old = $this->committeeRequest('ready');
        $old->legalReviews()->create(['verdict' => 'needs_document', 'legal_note' => 'ينقص مستند.']);
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $old->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request_id');
    }

    /** A second round supersedes the first while the history is preserved. */
    public function test_a_re_review_supersedes_the_first_while_the_history_is_preserved(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rapporteur = $this->userWithRole('R02');
        $legalOfficer = $this->userWithRole('R11');
        $requestRecord = $this->committeeRequest('under_legal_review');

        $this->actingAs($legalOfficer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                'recommendation' => 'decline',
                'approving_authority' => 'dean',
            ])->assertCreated();

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews/request")
            ->assertOk();

        $this->actingAs($legalOfficer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                'recommendation' => 'approve',
                'approving_authority' => 'minister',
            ])->assertCreated();

        $this->actingAs($legalOfficer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}/legal-reviews")
            ->assertOk()
            ->assertJsonCount(2, 'data.reviews')
            ->assertJsonPath('data.reviews.0.recommendation', 'decline')
            ->assertJsonPath('data.reviews.1.recommendation', 'approve');

        $this->assertSame('minister', $requestRecord->refresh()->latestLegalReview->approving_authority);
    }

    /** The مقرر's results form starts each request row from the legal member's choice. */
    public function test_the_meeting_carries_the_legal_recommendation_for_the_results_form(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');
        $legalOfficer = $this->userWithRole('R11');
        $meeting = $this->meetingFor($head);
        $requestRecord = $this->committeeRequest('under_legal_review');

        $this->actingAs($legalOfficer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                'recommendation' => 'decline',
                'approving_authority' => 'minister',
            ])->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestRecord->id])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}")
            ->assertOk()
            ->assertJsonPath('data.agenda_items.0.request.legal_recommendation.recommendation', 'decline')
            ->assertJsonPath('data.agenda_items.0.request.legal_recommendation.approving_authority', 'minister');
    }

    public function test_the_two_write_tiers_are_not_interchangeable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rapporteur = $this->userWithRole('R02');
        $legalOfficer = $this->userWithRole('R11');
        $requestRecord = $this->committeeRequest('ready');

        // Recording a verdict is the legal member's own act (Art. 14 (ب)).
        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", self::APPROVE)
            ->assertForbidden();

        // Dispatching a file is the rapporteur's coordinating act (Appendix 6).
        $this->actingAs($legalOfficer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews/request")
            ->assertForbidden();

        // Both may read it — Art. 21 requires the opinion to be readable.
        $this->actingAs($legalOfficer, 'sanctum')
            ->getJson('/api/legal-reviews')->assertOk();
        $this->actingAs($rapporteur, 'sanctum')
            ->getJson('/api/legal-reviews')->assertOk();
    }

    public function test_the_queue_lists_only_files_currently_with_the_legal_member(): void
    {
        $this->seed(DatabaseSeeder::class);
        $legalOfficer = $this->userWithRole('R11');

        $withLegal = $this->committeeRequest('under_legal_review');
        $this->committeeRequest('ready');            // rapporteur's candidate pool
        $this->committeeRequest('on_agenda');        // already scheduled

        $this->actingAs($legalOfficer, 'sanctum')
            ->getJson('/api/legal-reviews')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $withLegal->id);
    }

    /** R08's Settings switch: unreviewed and blocked files both reach the agenda. */
    public function test_the_bypass_setting_admits_any_pending_request_to_the_agenda(): void
    {
        $this->seed(DatabaseSeeder::class);
        Setting::where('key', Setting::LEGAL_REVIEW_BYPASS)->update(['value' => '1']);
        $head = $this->userWithRole('R03');
        $legalOfficer = $this->userWithRole('R11');
        $meeting = $this->meetingFor($head);

        $unreviewed = $this->committeeRequest('ready');
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $unreviewed->id])
            ->assertCreated();

        // A blocking verdict recorded before 2026-10-19 is waved through too.
        $blocked = $this->committeeRequest('ready');
        $blocked->legalReviews()->create(['verdict' => 'needs_document', 'legal_note' => 'ملاحظة.']);
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $blocked->id])
            ->assertCreated();
    }

    public function test_the_request_detail_screen_shows_the_latest_review_only(): void
    {
        $this->seed(DatabaseSeeder::class);
        $legalOfficer = $this->userWithRole('R11');
        $requestRecord = $this->committeeRequest('under_legal_review');

        $this->actingAs($legalOfficer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.legal_review', null)
            ->assertJsonPath('data.legal_reviews_count', 0);

        $this->actingAs($legalOfficer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                ...self::APPROVE,
                'legal_note' => 'تعارض محتمل مع المادة 178.',
            ])->assertCreated();

        $this->actingAs($legalOfficer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.legal_review.recommendation', 'approve')
            ->assertJsonPath('data.legal_review.permits_agenda', true)
            ->assertJsonPath('data.legal_reviews_count', 1);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }

    private function committeeRequest(string $statusCode, string $typeCode = 'PROM'): Request
    {
        $requestRecord = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'مراجعة قانونية سابقة للاجتماع',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', $typeCode)->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'submitted_at' => now(),
            'decision_grade' => 10,
        ]);

        // Stage 85 — [F] footer 2 now refuses an agenda insertion for a file
        // that does not cover its type's mandatory [D] Appendix 57 rows.
        // Supplied here so these tests stay about what they were written for;
        // the rule itself has its own coverage in DocumentCompletenessTest.
        $this->supplyRequiredDocuments($requestRecord);

        return $requestRecord->refresh();
    }

    private function meetingFor(User $head): Meeting
    {
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);

        return Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع دوري',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
    }
}
