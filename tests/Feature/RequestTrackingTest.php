<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStageLog;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Stage 89 — «متابعة طلباتي», the employee's own view of the files they filed.
 *
 * The stage is packaging rather than new data, so what these tests pin is the
 * packaging's own promises: the list is mine and only mine, it can be searched,
 * an expected completion date exists where a target duration does, and the
 * three registers reach the screen.
 */
class RequestTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_list_shows_only_the_callers_own_files(): void
    {
        $employee = $this->userWithRole('R01');
        $colleague = $this->userWithRole('R01');

        $mine = $this->requestFor($employee, title: 'طلب ترقية خاص بي');
        $theirs = $this->requestFor($colleague, title: 'طلب زميل');

        $response = $this->actingAs($employee, 'sanctum')
            ->getJson('/api/my-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $response->assertJsonPath('data.0.id', $mine->id);
        // Not merely "mine is present" — the colleague's file is genuinely
        // absent, which is the whole ownership framing this screen adds over
        // the internal work queue.
        $this->assertNotContains(
            $theirs->id,
            array_column($response->json('data'), 'id'),
        );

        // A list row carries the tracking block but none of the per-file
        // histories: ten durations and a full timeline per row is the N+1 the
        // panel's own endpoint exists to keep off this page.
        $row = $response->json('data.0');
        $this->assertArrayHasKey('is_concluded', $row);
        $this->assertArrayHasKey('responsibility', $row);
        foreach (['timeline', 'employee_notices', 'time_card'] as $register) {
            $this->assertArrayNotHasKey($register, $row);
        }
    }

    /**
     * The `search` param has existed on the request query since Stage 20 with
     * no UI exposing it; this screen is the first that does, and it searches
     * the receipt as well, because before the قيد that is the only number the
     * employee actually holds.
     */
    public function test_search_matches_the_tracking_number_the_receipt_and_the_subject(): void
    {
        $employee = $this->userWithRole('R01');

        $numbered = $this->requestFor($employee, title: 'طلب ندب');
        $numbered->forceFill([
            'reference_number' => 'PM-COM/2026/0777',
            'intake_receipt_number' => 'PM-RCV/2026/000777',
        ])->save();

        $other = $this->requestFor($employee, title: 'طلب علاوة');
        $other->forceFill(['reference_number' => 'PM-COM/2026/0888'])->save();

        foreach (['0777', 'PM-RCV/2026/000777', 'ندب'] as $term) {
            $this->actingAs($employee, 'sanctum')
                ->getJson('/api/my-requests?search='.urlencode($term))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $numbered->id);
        }

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/my-requests?search=PM-COM')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertNotNull($other->fresh());
    }

    /**
     * Open vs concluded reads the union ReportMetricsService::overdueQuery()
     * already treats as "has left the pipeline" — no fourth definition of
     * finished is invented for a screen filter.
     */
    public function test_the_scope_toggle_splits_open_work_from_concluded_work(): void
    {
        $employee = $this->userWithRole('R01');

        $open = $this->requestFor($employee, statusCode: 'in_review');
        $completed = $this->requestFor($employee, statusCode: 'completed_closed');
        $refused = $this->requestFor($employee, statusCode: 'not_approved');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/my-requests?scope=open')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id)
            // The list carries the concluded flag itself, so a closed file's
            // row never renders an "expected by ⟨date⟩" line computed off a
            // target nothing is counting against any more.
            ->assertJsonPath('data.0.is_concluded', false);

        $concluded = $this->actingAs($employee, 'sanctum')
            ->getJson('/api/my-requests?scope=concluded')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // An abandoned outcome counts as concluded too: a refused file is over
        // for the employee, even though it never "completed".
        $this->assertEqualsCanonicalizing(
            [$completed->id, $refused->id],
            array_column($concluded->json('data'), 'id'),
        );

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/my-requests?scope=all')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/my-requests?scope=nonsense')
            ->assertStatus(422);
    }

    /**
     * The Done-when's third clause: "is told when the current step is expected
     * to complete". The date is the stage's own entry timestamp plus its
     * seeded Appendix 37 target, not a new number.
     */
    public function test_expected_by_is_the_entry_date_plus_the_stages_own_target(): void
    {
        $employee = $this->userWithRole('R01');
        // requirements_check is seeded 2/2 days by WorkflowStageSeeder.
        $requestRecord = $this->requestFor($employee, stageCode: 'requirements_check');
        $enteredAt = now()->subDay();
        $this->logArrival($requestRecord, 'requirements_check', $enteredAt);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/my-requests')
            ->assertOk()
            ->assertJsonPath('data.0.stage_timeliness.target_days_max', 2)
            ->assertJsonPath('data.0.stage_timeliness.elapsed_days', 1)
            ->assertJsonPath(
                'data.0.stage_timeliness.expected_by',
                $enteredAt->copy()->startOfDay()->addDays(2)->toDateString(),
            );
    }

    /**
     * Three of the twelve stages have no sourced duration in Appendix 37. The
     * screen says so rather than inventing one, so the payload must stay null
     * rather than fall back to anything.
     */
    public function test_a_stage_with_no_sourced_target_reports_no_timeliness_at_all(): void
    {
        $employee = $this->userWithRole('R01');
        $requestRecord = $this->requestFor($employee, stageCode: 'forward_to_committee');
        $this->logArrival($requestRecord, 'forward_to_committee', now()->subDays(40));

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/my-requests')
            ->assertOk()
            ->assertJsonPath('data.0.stage_timeliness', null);
    }

    /** The three registers Stage 89 names, on one of my own files. */
    public function test_the_tracking_panel_carries_the_timeline_notices_and_time_card(): void
    {
        $employee = $this->userWithRole('R01');
        $requestRecord = $this->requestFor($employee, stageCode: 'requirements_check');
        $this->logArrival($requestRecord, 'requirements_check', now()->subDay());
        // A real Art. 101 notice, delivered the way the observer delivers one.
        $this->moveStatus($requestRecord, 'registered');

        $response = $this->actingAs($employee, 'sanctum')
            ->getJson("/api/my-requests/{$requestRecord->id}")
            ->assertOk();

        $response
            ->assertJsonPath('data.id', $requestRecord->id)
            ->assertJsonPath('data.is_concluded', false)
            ->assertJsonPath('data.documents_complete', true)
            // Appendix 17/18's pair, inherited from RequestResource.
            ->assertJsonPath('data.responsibility.responsible.code', 'committee_rapporteur')
            ->assertJsonCount(1, 'data.timeline')
            ->assertJsonPath('data.timeline.0.action', 'register')
            ->assertJsonCount(1, 'data.employee_notices')
            ->assertJsonPath('data.employee_notices.0.moment', 'received');

        // Appendix 71's ten segments, every one of them present even when the
        // file has not reached the moment that closes it.
        $this->assertCount(10, $response->json('data.time_card'));

        // Deliberately absent: the committee's own machinery stays on the
        // workspace payload. A later change that widens this resource should
        // be a decision, not a side effect.
        foreach (['control_gates', 'jurisdiction_test', 'closure_audit', 'approval_returns', 'available_actions'] as $internal) {
            $this->assertArrayNotHasKey($internal, $response->json('data'));
        }
    }

    public function test_a_concluded_file_is_reported_as_concluded(): void
    {
        $employee = $this->userWithRole('R01');
        $requestRecord = $this->requestFor($employee, statusCode: 'completed_closed');

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/my-requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.is_concluded', true);
    }

    /**
     * «طلباتي» means mine, with no admin exemption — R08 reaches every file
     * through the ordinary workspace, which this stage leaves untouched.
     */
    public function test_someone_elses_file_is_absent_from_this_screen_for_everyone_including_an_admin(): void
    {
        $employee = $this->userWithRole('R01');
        $requestRecord = $this->requestFor($employee);

        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->getJson("/api/my-requests/{$requestRecord->id}")
            ->assertNotFound();

        $admin = $this->userWithRole('R08');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/my-requests/{$requestRecord->id}")
            ->assertNotFound();

        // ...and the workspace still opens it for that same admin, so nothing
        // was taken away by scoping this screen.
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();
    }

    public function test_a_role_without_the_grant_is_refused(): void
    {
        // R07 holds no request_intake grant either — the dean approves, they
        // do not file — so they have nothing to track and no screen for it.
        $this->actingAs($this->userWithRole('R07'), 'sanctum')
            ->getJson('/api/my-requests')
            ->assertForbidden();
    }

    // --- fixtures ---------------------------------------------------------

    private function logArrival(Request $requestRecord, string $stageCode, Carbon $at): void
    {
        RequestStageLog::create([
            'request_id' => $requestRecord->id,
            'to_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'action' => 'register',
            'acted_by_user_id' => $requestRecord->created_by_user_id,
            'acted_at' => $at,
        ]);
    }

    private function moveStatus(Request $requestRecord, string $toCode): void
    {
        $requestRecord->refresh();

        $to = RequestStatus::where('code', $toCode)->value('id');

        RequestStatusHistory::create([
            'request_id' => $requestRecord->id,
            'from_status_id' => $requestRecord->status_id,
            'to_status_id' => $to,
            'changed_by_user_id' => $this->userWithRole('R02')->id,
            'changed_at' => now(),
        ]);

        $requestRecord->forceFill(['status_id' => $to])->save();
    }

    private function requestFor(
        User $creator,
        string $stageCode = 'receive_from_municipality',
        string $statusCode = 'new',
        string $title = 'طلب لاختبار متابعة الطلبات',
    ): Request {
        return Request::create([
            'reference_number' => 'PM-COM/2026/'.fake()->unique()->numerify('####'),
            'title' => $title,
            'description' => 'وصف الطلب.',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $creator->id,
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
