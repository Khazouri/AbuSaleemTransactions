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
use Illuminate\Support\Facades\Storage;
use Tests\RecordsStructuredDecisions;
use Tests\TestCase;

/**
 * Stage 82 — [D] Art. 85's per-item study sequence (النموذج 11's card),
 * Appendix 25's five إلزامية stages and its rule that the material is frozen
 * once voting begins.
 */
class StudySequenceTest extends TestCase
{
    use RecordsStructuredDecisions;
    use RefreshDatabase;

    private const ORDERED_STEPS = [
        'subject_presented',
        'facts_presented',
        'documents_reviewed',
        'legal_opinion_presented',
        'discussion_held',
        'discussion_closed',
    ];

    public function test_the_card_lists_art_85s_nine_steps_with_its_two_derived_ones(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, , $meeting, $item] = $this->fixture();

        $steps = collect(
            $this->actingAs($head, 'sanctum')
                ->getJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/study-sequence")
                ->assertOk()
                ->json('data.steps'),
        );

        $this->assertCount(9, $steps);
        $this->assertSame('subject_presented', $steps->first()['code']);
        $this->assertSame('result_recorded', $steps->last()['code']);
        $this->assertSame('derived', $steps->firstWhere('code', 'vote_taken')['mode']);
        $this->assertSame('derived', $steps->firstWhere('code', 'result_recorded')['mode']);
        $this->assertSame('optional', $steps->firstWhere('code', 'clarifications_requested')['mode']);
        // The request carries a legal review, so Art. 85's "عند وجوده" step
        // applies to it.
        $this->assertTrue($steps->firstWhere('code', 'legal_opinion_presented')['applicable']);
        $this->assertFalse($this->cardIsComplete($head, $meeting, $item));
    }

    public function test_a_step_cannot_be_marked_before_the_one_that_precedes_it(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, , $meeting, $item] = $this->fixture();

        $this->markStep($head, $meeting, $item, 'discussion_closed')
            ->assertStatus(422);

        $this->markStep($head, $meeting, $item, 'subject_presented')->assertOk();
        $this->markStep($head, $meeting, $item, 'facts_presented')->assertOk();
        $this->markStep($head, $meeting, $item, 'documents_reviewed')->assertOk();
        $this->markStep($head, $meeting, $item, 'legal_opinion_presented')->assertOk();
        $this->markStep($head, $meeting, $item, 'discussion_held')->assertOk();
        $this->markStep($head, $meeting, $item, 'discussion_closed')->assertOk();

        $this->assertTrue($this->cardIsComplete($head, $meeting, $item));
    }

    public function test_the_legal_step_is_not_applicable_without_a_recorded_legal_opinion(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, , $meeting] = $this->fixture();

        // An administrative item has no legal card of its own, so Art. 85's
        // conditional step simply does not apply to it.
        $adminItem = $meeting->agendaItems()->create([
            'item_type' => 'administrative', 'subject' => 'بند إداري', 'agenda_order' => 9,
        ]);

        $steps = collect(
            $this->actingAs($head, 'sanctum')
                ->getJson("/api/meetings/{$meeting->id}/agenda/{$adminItem->id}/study-sequence")
                ->assertOk()
                ->json('data.steps'),
        );

        $this->assertFalse($steps->firstWhere('code', 'legal_opinion_presented')['applicable']);

        $this->markStep($head, $meeting, $adminItem, 'legal_opinion_presented')
            ->assertStatus(422);

        foreach (['subject_presented', 'facts_presented', 'documents_reviewed', 'discussion_held', 'discussion_closed'] as $step) {
            $this->markStep($head, $meeting, $adminItem, $step)->assertOk();
        }

        $this->assertTrue($this->cardIsComplete($head, $meeting, $adminItem));
    }

    public function test_voting_is_refused_until_the_sequence_completes_and_the_worklist_agrees(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, $meeting, $item] = $this->fixture();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/votes", ['vote' => 'approve'])
            ->assertStatus(422);

        // The same fact drives DecisionEligibility's worklist SQL, so the item
        // must not be offered while the endpoint would refuse it.
        $this->assertSame([], $this->pendingIds($member));

        $this->completeSequence($head, $meeting, $item);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/votes", ['vote' => 'approve'])
            ->assertCreated();

        $this->assertSame([$item->id], $this->pendingIds($head));
    }

    public function test_a_step_cannot_be_untied_once_voting_has_begun(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, $meeting, $item] = $this->fixture();
        $this->completeSequence($head, $meeting, $item);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/votes", ['vote' => 'approve'])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/study-sequence", [
                'step' => 'discussion_closed',
                'done' => false,
            ])
            ->assertStatus(422);

        $this->assertNotNull($item->refresh()->study_sequence_completed_at);
    }

    public function test_appendix_25_freezes_the_documents_and_the_memo_once_voting_has_begun(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');

        [$head, $member, $meeting, $item, $requestRecord] = $this->fixture();

        $upload = fn () => $this->actingAs($requestRecord->createdBy, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('doc.pdf', 12, 'application/pdf'),
                // Stage 91 — this test's subject is Appendix 25's freeze, so
                // the document question is answered the generic way.
                'required_document_key' => 'other',
                'file_section' => 'supporting_documents',
            ]);

        // Before any vote, the file is still open for documents.
        $upload()->assertCreated();

        $this->completeSequence($head, $meeting, $item);
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/votes", ['vote' => 'approve'])
            ->assertCreated();

        $upload()->assertStatus(422);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/presentation-memo/generate")
            ->assertStatus(422);
    }

    public function test_recording_a_decision_is_refused_while_the_sequence_is_incomplete(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, $meeting, $item] = $this->fixture();
        $this->completeSequence($head, $meeting, $item);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/votes", ['vote' => 'defer'])
            ->assertCreated();

        // Rolled back directly, i.e. the one case existing votes do not imply
        // a complete sequence — which is why record() re-checks rather than
        // trusting them.
        $item->forceFill(['study_sequence_completed_at' => null])->save();

        $this->actingAs($head, 'sanctum')
            ->postJson(
                "/api/meetings/{$meeting->id}/agenda/{$item->id}/decision",
                $this->decisionPayload('defer', ['comment' => 'يؤجل لاستكمال المستند.']),
            )
            ->assertStatus(422);

        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_only_the_chair_may_tick_a_step_but_anyone_may_read_the_card(): void
    {
        $this->seed(DatabaseSeeder::class);

        [, $member, $meeting, $item] = $this->fixture();

        $this->actingAs($member, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/study-sequence")
            ->assertOk();

        $this->markStep($member, $meeting, $item, 'subject_presented')
            ->assertForbidden();
    }

    // --- helpers -----------------------------------------------------------

    private function markStep(User $actor, Meeting $meeting, MeetingRequest $item, string $step)
    {
        return $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/study-sequence", [
                'step' => $step,
                'done' => true,
            ]);
    }

    private function completeSequence(User $actor, Meeting $meeting, MeetingRequest $item): void
    {
        foreach (self::ORDERED_STEPS as $step) {
            $this->markStep($actor, $meeting, $item, $step)->assertOk();
        }
    }

    private function cardIsComplete(User $actor, Meeting $meeting, MeetingRequest $item): bool
    {
        return (bool) $this->actingAs($actor, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$item->id}/study-sequence")
            ->json('data.is_complete');
    }

    /** @return list<int> */
    private function pendingIds(User $actor): array
    {
        return collect(
            $this->actingAs($actor, 'sanctum')->getJson('/api/decisions/pending')->json('data'),
        )->pluck('id')->all();
    }

    /** @return array{0: User, 1: User, 2: Meeting, 3: MeetingRequest, 4: Request} */
    private function fixture(): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');
        $employee = $this->userWithRole('R01');

        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع دراسة البنود',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
            // Stage 99 — Art. 84: deliberation waits for the agenda's adoption.
            'agenda_adopted_at' => now(),
        ]);
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);
        $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);

        $requestRecord = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب ترقية',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $employee->id,
            'submitted_at' => now(),
        ]);
        $requestRecord->legalReviews()->create([
            'verdict' => 'sound_ready',
            'committee_mandate' => 'decision',
            'reviewed_at' => now(),
        ]);

        $item = $meeting->agendaItems()->create([
            'request_id' => $requestRecord->id,
            'agenda_order' => 1,
        ]);

        return [$head, $member, $meeting, $item, $requestRecord->refresh()];
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
