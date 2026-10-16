<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\Request;
use App\Models\RequestLegalReview;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PassesControlGates;
use Tests\TestCase;

/**
 * Stage 68 — [D] Art. 21 / [E] stage 08's pre-meeting legal review: the review
 * record itself, Art. 38's status 07, the five-verdict routing, and the
 * agenda-insertion gate that is this stage's own done-when.
 */
class RequestLegalReviewTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;

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
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", ['verdict' => 'sound_ready'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('verdict');

        // The status move runs first inside the same transaction, so a refused
        // move must leave no review row behind either.
        $this->assertSame(0, $requestRecord->legalReviews()->count());
    }

    /** Art. 21's five outcomes, each landing on the status [E] stage 08 routes it to. */
    public function test_each_of_the_five_verdicts_lands_on_the_right_status(): void
    {
        $this->seed(DatabaseSeeder::class);
        $legalOfficer = $this->userWithRole('R11');

        $expected = [
            'sound_ready' => 'ready',
            'present_with_note' => 'ready',
            'needs_document' => 'completion_required',
            'needs_clarification' => 'completion_required',
            'jurisdiction_note' => 'completion_required',
        ];

        foreach ($expected as $verdict => $statusCode) {
            $requestRecord = $this->committeeRequest('under_legal_review');

            $this->actingAs($legalOfficer, 'sanctum')
                ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                    'verdict' => $verdict,
                    'legal_note' => 'ملاحظة العضو القانوني.',
                ])
                ->assertCreated()
                ->assertJsonPath('data.verdict', $verdict)
                ->assertJsonPath(
                    'data.permits_agenda',
                    in_array($verdict, RequestLegalReview::PERMITTING_VERDICTS, strict: true),
                );

            $this->assertSame($statusCode, $requestRecord->refresh()->status->code, "verdict {$verdict}");
        }
    }

    public function test_appendix_22_card_fields_round_trip_and_the_type_prefills_appendix_21_legal_basis(): void
    {
        $this->seed(DatabaseSeeder::class);
        $legalOfficer = $this->userWithRole('R11');
        $requestRecord = $this->committeeRequest('under_legal_review');

        $this->actingAs($legalOfficer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                'verdict' => 'sound_ready',
                'primary_legislation' => 'قانون علاقات العمل',
                'article_reference' => 'المادة 135',
                'supplementary_decision' => 'قرار وزير الحكم المحلي رقم 1500 لسنة 2022',
                'committee_mandate' => 'recommendation',
                'approving_body' => 'عميد البلدية',
                'requires_central_approval' => 'needs_verification',
                'legal_deadline' => '30 يومًا',
                'prohibiting_conditions' => 'لا توجد شروط مانعة.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.committee_mandate', 'recommendation')
            ->assertJsonPath('data.requires_central_approval', 'needs_verification')
            ->assertJsonPath('data.article_reference', 'المادة 135');

        // Appendix 21's per-subject legal basis rides request_types and is
        // echoed so the form can pre-fill Appendix 22's card. PROM is one of
        // the six subjects the appendix actually names.
        $this->actingAs($legalOfficer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}/legal-reviews")
            ->assertOk()
            ->assertJsonPath('data.legal_basis.primary_legislation', 'المواد المنظمة لشغل الوظائف والترقية')
            ->assertJsonCount(1, 'data.reviews');
    }

    /** Appendix 21 covers only six of the twelve types; the rest are an honest null. */
    public function test_a_type_appendix_21_does_not_cover_reports_a_null_legal_basis(): void
    {
        $this->seed(DatabaseSeeder::class);
        $legalOfficer = $this->userWithRole('R11');
        $requestRecord = $this->committeeRequest('under_legal_review', typeCode: 'LEAV');

        $this->actingAs($legalOfficer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}/legal-reviews")
            ->assertOk()
            ->assertJsonPath('data.legal_basis.primary_legislation', null)
            ->assertJsonPath('data.legal_basis.procedural_note', null);
    }

    public function test_every_verdict_but_sound_ready_requires_the_legal_members_note(): void
    {
        $this->seed(DatabaseSeeder::class);
        $legalOfficer = $this->userWithRole('R11');

        foreach (['needs_document', 'needs_clarification', 'jurisdiction_note', 'present_with_note'] as $verdict) {
            $requestRecord = $this->committeeRequest('under_legal_review');

            $this->actingAs($legalOfficer, 'sanctum')
                ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", ['verdict' => $verdict])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('legal_note');

            $this->assertSame('under_legal_review', $requestRecord->refresh()->status->code);
        }

        // sound_ready has nothing to state, so it may omit the note.
        $requestRecord = $this->committeeRequest('under_legal_review');
        $this->actingAs($legalOfficer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", ['verdict' => 'sound_ready'])
            ->assertCreated();
    }

    /**
     * The stage's own done-when, half one: an unreviewed or blocked file
     * cannot reach the agenda; the two permitting verdicts can.
     */
    public function test_the_agenda_gate_admits_only_the_two_permitting_verdicts(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');
        $legalOfficer = $this->userWithRole('R11');
        $meeting = $this->meetingFor($head);

        // No review at all.
        $unreviewed = $this->committeeRequest('ready');
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $unreviewed->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request_id');

        foreach (RequestLegalReview::VERDICTS as $verdict) {
            $requestRecord = $this->committeeRequest('under_legal_review');

            $this->actingAs($legalOfficer, 'sanctum')
                ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                    'verdict' => $verdict,
                    'legal_note' => 'ملاحظة.',
                ])->assertCreated();

            $response = $this->actingAs($head, 'sanctum')
                ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestRecord->id]);

            if (in_array($verdict, RequestLegalReview::PERMITTING_VERDICTS, strict: true)) {
                $response->assertCreated();
            } else {
                $response->assertUnprocessable()->assertJsonValidationErrors('request_id');
            }
        }
    }

    /** [E] stage 08's loop: correct, re-review, and the latest verdict is what counts. */
    public function test_a_re_review_supersedes_the_first_while_the_history_is_preserved(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rapporteur = $this->userWithRole('R02');
        $legalOfficer = $this->userWithRole('R11');
        $head = $this->userWithRole('R03');
        $meeting = $this->meetingFor($head);

        $requestRecord = $this->committeeRequest('under_legal_review');

        $this->actingAs($legalOfficer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", [
                'verdict' => 'needs_document',
                'legal_note' => 'ينقص قرار التعيين.',
            ])->assertCreated();

        $this->assertSame('completion_required', $requestRecord->refresh()->status->code);

        // Stage 69 — this used to write status_id by hand, because
        // `completion_required` had no route back to legal review at all: the
        // loop Art. 21 and [E] stage 08 both describe was unreachable through
        // any endpoint. Validating the map against Appendix 5 surfaced it, so
        // the re-dispatch below now walks the real path.
        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews/request")
            ->assertOk();

        $this->actingAs($legalOfficer, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", ['verdict' => 'sound_ready'])
            ->assertCreated();

        // Art. 21 requires the earlier opinion to stay readable in the file.
        $this->actingAs($legalOfficer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}/legal-reviews")
            ->assertOk()
            ->assertJsonCount(2, 'data.reviews')
            ->assertJsonPath('data.reviews.0.verdict', 'needs_document')
            ->assertJsonPath('data.reviews.0.legal_note', 'ينقص قرار التعيين.')
            ->assertJsonPath('data.reviews.1.verdict', 'sound_ready');

        // The gate reads only the latest.
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", ['request_id' => $requestRecord->id])
            ->assertCreated();
    }

    public function test_the_two_write_tiers_are_not_interchangeable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rapporteur = $this->userWithRole('R02');
        $legalOfficer = $this->userWithRole('R11');
        $requestRecord = $this->committeeRequest('ready');

        // Recording a verdict is the legal member's own act (Art. 14 (ب)).
        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/legal-reviews", ['verdict' => 'sound_ready'])
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

    /** Appendix 7's "هل تمت المراجعة القانونية المطلوبة؟", for rows predating the gate. */
    public function test_readiness_flags_a_legacy_agenda_item_with_no_permitting_review(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');
        $meeting = $this->meetingFor($head);
        $requestRecord = $this->committeeRequest('ready');

        // Written directly, the way a row inserted before Stage 68 existed
        // would look — the HTTP endpoint would refuse this today.
        $meeting->agendaItems()->create([
            'request_id' => $requestRecord->id,
            'agenda_order' => 1,
        ]);

        $exceptionCodes = $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/readiness")
            ->assertOk()
            ->json('data.exceptions.*.code');

        $this->assertContains('missing_legal_review', $exceptionCodes);
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
                'verdict' => 'present_with_note',
                'legal_note' => 'تعارض محتمل مع المادة 178.',
            ])->assertCreated();

        $this->actingAs($legalOfficer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.legal_review.verdict', 'present_with_note')
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
