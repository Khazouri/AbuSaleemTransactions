<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Decision;
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
use Tests\TestCase;

/**
 * Stage 58, Track J — the foundational appeals entity: create + list only,
 * scoped to the acting user. No ownership/decided-status/duplication
 * validation yet (Stage 59), so this suite only proves the schema/screen
 * exist and behave per the stage's own done-when.
 */
class AppealTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_appeal_round_trips_through_create_and_list(): void
    {
        $this->seed(DatabaseSeeder::class);

        $employee = $this->userWithRole('R01');
        $target = $this->requestFixture();

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', ['original_request_id' => $target->id])
            ->assertCreated()
            ->assertJsonPath('data.appellant.id', $employee->id)
            ->assertJsonPath('data.original_request.id', $target->id)
            ->assertJsonPath('data.status.code', 'submitted');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/appeals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.original_request.reference_number', $target->reference_number);
    }

    public function test_the_appellant_is_always_the_acting_user_regardless_of_client_input(): void
    {
        $this->seed(DatabaseSeeder::class);

        $employee = $this->userWithRole('R01');
        $someoneElse = $this->userWithRole('R01');
        $target = $this->requestFixture();

        // appellant_user_id isn't even a validated field — passing it must
        // have zero effect on who the row records as the filer.
        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', [
                'original_request_id' => $target->id,
                'appellant_user_id' => $someoneElse->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.appellant.id', $employee->id);

        $this->assertDatabaseHas('appeals', [
            'original_request_id' => $target->id,
            'appellant_user_id' => $employee->id,
        ]);
    }

    public function test_a_non_admin_actor_sees_only_their_own_appeals_while_r08_sees_all(): void
    {
        $this->seed(DatabaseSeeder::class);

        $first = $this->userWithRole('R01');
        $second = $this->userWithRole('R01');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();

        $this->actingAs($first, 'sanctum')
            ->postJson('/api/appeals', ['original_request_id' => $this->requestFixture()->id])
            ->assertCreated();
        $this->actingAs($second, 'sanctum')
            ->postJson('/api/appeals', ['original_request_id' => $this->requestFixture()->id])
            ->assertCreated();

        $this->actingAs($first, 'sanctum')
            ->getJson('/api/appeals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.appellant.id', $first->id);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/appeals')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_role_without_appeals_add_permission_is_refused(): void
    {
        $this->seed(DatabaseSeeder::class);

        $reviewer = $this->userWithRole('R02');
        $target = $this->requestFixture();

        $this->actingAs($reviewer, 'sanctum')
            ->postJson('/api/appeals', ['original_request_id' => $target->id])
            ->assertForbidden();

        // `view` is broad, unlike `add` — R02 can still see the (empty) list.
        $this->actingAs($reviewer, 'sanctum')
            ->getJson('/api/appeals')
            ->assertOk();
    }

    public function test_an_appeal_can_target_a_recorded_decision_or_fall_back_to_a_free_text_reference(): void
    {
        $this->seed(DatabaseSeeder::class);

        $employee = $this->userWithRole('R01');

        // With a real Decision row.
        $decidedRequest = $this->requestFixture();
        $committee = Committee::create(['name_ar' => 'لجنة اختبار التظلمات']);
        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع صدر عنه قرار',
            'status' => 'completed',
            'scheduled_at' => now()->subWeek(),
            'created_by_user_id' => $employee->id,
        ]);
        $agendaItem = MeetingRequest::create([
            'meeting_id' => $meeting->id,
            'request_id' => $decidedRequest->id,
            'agenda_order' => 1,
            'item_type' => 'employee_request',
        ]);
        $decision = Decision::create([
            'meeting_request_id' => $agendaItem->id,
            'outcome' => 'approve',
            'votes_approve_count' => 2,
            'votes_reject_count' => 0,
            'votes_defer_count' => 0,
            'decided_by_user_id' => $employee->id,
            'decided_at' => now(),
        ]);

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', [
                'original_request_id' => $decidedRequest->id,
                'original_decision_id' => $decision->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.original_decision_id', $decision->id);

        // Without one — the free-text fallback (e.g. requirements_check's
        // reject_formally, which writes no Decision row at all).
        $undecidedRequest = $this->requestFixture();

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', [
                'original_request_id' => $undecidedRequest->id,
                'original_decision_reference' => 'قرار رفض شكلي رقم 12',
                'original_decision_date' => '2026-08-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.original_decision_id', null)
            ->assertJsonPath('data.original_decision_reference', 'قرار رفض شكلي رقم 12');
    }

    public function test_a_nonexistent_target_is_rejected(): void
    {
        $this->seed(DatabaseSeeder::class);

        $employee = $this->userWithRole('R01');

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', ['original_request_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('original_request_id');

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/appeals', [
                'original_request_id' => $this->requestFixture()->id,
                'original_decision_id' => 999999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('original_decision_id');

        $this->assertDatabaseCount('appeals', 0);
    }

    // --- helpers ------------------------------------------------------------

    private function requestFixture(): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب صدر بشأنه قرار',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'final_approved')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'final_approval_archiving')->value('id'),
            'submitted_at' => now(),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
