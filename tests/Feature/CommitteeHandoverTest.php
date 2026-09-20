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
use App\Models\WorkflowTransition;
use App\Services\Lifecycle\RequestResponsibilityService;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 96 — both hops into the committee (`observations ->
 * forward_to_committee` and `forward_to_committee -> receive_from_committee`)
 * belong to مقرر اللجنة (R02).
 *
 * Stage 86 gave them to R09 (أمين سر اللجنة) on [F] step 7. [D]'s الملحق
 * السادس — the governing RACI matrix — has no أمين سر اللجنة column at all and
 * gives جدولة/عرض الملف على اللجنة to مقرر اللجنة as a single مسؤول, so this
 * file now pins the reversal rather than the move.
 *
 * The visibility assertions are as much the point as the role gate:
 * RequestVisibility derives its assignment clause from these very rows, so the
 * re-seed alone hands R02 the file and takes it from R09 — with no bounded
 * clause of the kind Stages 47/68/75/76/77/78/83 each had to add for a role
 * that held no row at all.
 */
class CommitteeHandoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_rapporteur_takes_the_studied_file_onward_and_the_secretary_cannot(): void
    {
        $this->assertHandoverMoved(
            fromStage: 'observations',
            statusCode: 'in_review',
            toStage: 'forward_to_committee',
        );
    }

    public function test_the_rapporteur_hands_the_file_to_the_committee_and_the_secretary_cannot(): void
    {
        $this->assertHandoverMoved(
            fromStage: 'forward_to_committee',
            statusCode: 'ready',
            toStage: 'receive_from_committee',
        );
    }

    private function assertHandoverMoved(
        string $fromStage,
        string $statusCode,
        string $toStage,
    ): void {
        $this->seed(DatabaseSeeder::class);

        $service = app(WorkflowService::class);
        $rapporteur = $this->userWithRole('R02');
        $secretary = $this->userWithRole('R09');

        try {
            $service->transition($this->newRequest($fromStage, $statusCode), 'forward', $secretary);
            $this->fail("R09 should no longer hold the handover out of {$fromStage}.");
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame(
                'لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.',
                $exception->getMessage(),
            );
        }

        $moved = $service->transition($this->newRequest($fromStage, $statusCode), 'forward', $rapporteur);

        $this->assertSame($toStage, $moved->currentStage->code);
        $this->assertDatabaseHas('request_stage_logs', [
            'request_id' => $moved->id,
            'action' => 'forward',
            'acted_by_user_id' => $rapporteur->id,
        ]);
    }

    /**
     * The gate and the preview must agree — WorkflowService::actorMayUse()'s
     * own invariant. A button the endpoint would refuse is the specific bug
     * that predicate exists to prevent.
     */
    public function test_the_detail_screen_offers_the_handover_to_the_rapporteur_and_hides_the_file_from_the_secretary(): void
    {
        $this->seed(DatabaseSeeder::class);

        $requestRecord = $this->newRequest('observations', 'in_review');

        $actions = $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.available_actions.0', 'forward')
            ->json('data.available_actions');

        // R02 never lost its seat at the study stage; Stage 96 hands the
        // forward back on top of the `request_edit` it kept throughout.
        $this->assertContains('request_edit', $actions);

        // R09 holds no row at this stage any more, so the file is not merely
        // unactionable for it — it is invisible, through the same mechanism
        // that made it visible under Stage 86.
        $this->actingAs($this->userWithRole('R09'), 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertNotFound();
    }

    /**
     * No RequestVisibility change was needed for this stage, and this is what
     * proves it: the assignment clause reads workflow_transitions, so the
     * re-seed alone hands R02 the file and takes it from R09.
     */
    public function test_visibility_follows_the_re_seeded_rows_at_the_forwarding_stage(): void
    {
        $this->seed(DatabaseSeeder::class);

        $creator = $this->userWithRole('R01');
        $requestRecord = $this->newRequest('forward_to_committee', 'ready', $creator->id);

        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();

        $this->actingAs($this->userWithRole('R09'), 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertNotFound();
    }

    /** Cancellation follows the role that moves the stage — the seeder's own stated rule. */
    public function test_cancelling_at_the_forwarding_stage_moved_to_the_rapporteur_with_no_stale_row_left_behind(): void
    {
        $this->seed(DatabaseSeeder::class);

        $service = app(WorkflowService::class);

        try {
            $service->transition(
                $this->newRequest('forward_to_committee', 'ready'),
                'cancel',
                $this->userWithRole('R09'),
                'إلغاء إداري.',
            );
            $this->fail('R09 should no longer hold cancel at forward_to_committee.');
        } catch (WorkflowTransitionException $exception) {
            $this->assertSame(
                'لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.',
                $exception->getMessage(),
            );
        }

        $cancelled = $service->transition(
            $this->newRequest('forward_to_committee', 'ready'),
            'cancel',
            $this->userWithRole('R02'),
            'إلغاء بناءً على كتاب رسمي.',
        );

        $this->assertSame('cancelled', $cancelled->status->code);
        $this->assertSame('forward_to_committee', $cancelled->currentStage->code);
    }

    /**
     * Re-running the seeder must not leave a superseded cancel row beside its
     * replacement: exception rows survive the delete at the top of
     * WorkflowTransitionSeeder::run(), and seedException() keys its upsert on
     * required_role_id, so without the explicit cleanup both would exist. That
     * one query has now swept R05 (pre-Stage-86) and R09 (pre-Stage-96) alike.
     */
    public function test_re_seeding_a_pre_stage_96_database_removes_the_superseded_cancel_row(): void
    {
        $this->seed(DatabaseSeeder::class);

        $stageId = WorkflowStage::where('code', 'forward_to_committee')->value('id');
        $cancelRows = fn () => WorkflowTransition::query()
            ->where('from_stage_id', $stageId)
            ->where('action', 'cancel')
            ->with('requiredRole')
            ->get();

        // A fresh database never holds the stale row, so seeding twice would
        // prove nothing. Put the row back the way a pre-Stage-96 install
        // actually has it, which is the only state the cleanup exists for.
        $cancelRows()->first()->update(['required_role_id' => Role::where('code', 'R09')->value('id')]);

        $this->seed(DatabaseSeeder::class);

        $rows = $cancelRows();

        $this->assertCount(1, $rows);
        $this->assertSame('R02', $rows->first()->requiredRole->code);
    }

    /**
     * Appendix 17's المسؤول الحالي is derived from these same rows (Stage 83),
     * so it follows the re-seed with no separate change — R02 maps onto the
     * appendix's مقرر اللجنة, a mapping ROLE_TO_PARTY already held.
     */
    public function test_the_current_responsible_party_names_the_rapporteur_at_both_handover_stages(): void
    {
        $this->seed(DatabaseSeeder::class);

        $service = app(RequestResponsibilityService::class);

        foreach (['observations' => 'in_review', 'forward_to_committee' => 'ready'] as $stageCode => $statusCode) {
            $answer = $service->for($this->newRequest($stageCode, $statusCode));

            $this->assertSame('committee_rapporteur', $answer['responsible']['code'], $stageCode);
            $this->assertSame('مقرر اللجنة', $answer['responsible']['ar'], $stageCode);
        }
    }

    private function newRequest(
        string $stageCode,
        string $statusCode,
        ?int $createdByUserId = null,
    ): Request {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'اختبار تسليم الملف إلى اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'submitted_at' => now(),
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
