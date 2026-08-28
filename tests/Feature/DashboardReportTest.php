<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Stage 24 — dashboard KPIs, the reports screen, and its exports. */
class DashboardReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        // Cycle times are measured in fractions of a day, so a fixture built
        // over a few real milliseconds would make the expected averages
        // approximate. Frozen, they are exact.
        $this->freezeTime();
    }

    /**
     * The Stage 24 done-when, first half: the dashboard reflects real data.
     *
     * The fixture is deliberately hand-built rather than factory-random, so
     * every number below is arithmetic anyone can check by reading it: six
     * requests, two of them finished (in four and five days), one
     * cancelled, one overdue and still open.
     */
    public function test_the_dashboard_reports_real_kpis(): void
    {
        $this->buildFixture();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/dashboard')
            ->assertOk();

        $response
            ->assertJsonPath('data.kpis.total', 6)
            ->assertJsonPath('data.kpis.completed', 2)
            ->assertJsonPath('data.kpis.abandoned', 1)
            // Pending is the remainder: 6 - 2 completed - 1 cancelled.
            ->assertJsonPath('data.kpis.pending', 3)
            ->assertJsonPath('data.kpis.completion_rate', 33.3)
            // The second overdue row has since been archived, so it is history
            // rather than an outstanding breach.
            ->assertJsonPath('data.kpis.overdue', 1)
            ->assertJsonPath('data.kpis.average_cycle_days', 4.5);

        $byStatus = collect($response->json('data.breakdowns.by_status'));
        $this->assertSame(1, $byStatus->firstWhere('code', 'cancelled')['total']);
        $this->assertSame(1, $byStatus->firstWhere('code', 'archived')['total']);
        // Every status appears, even the ones nothing is sitting in.
        $this->assertSame(0, $byStatus->firstWhere('code', 'deferred')['total']);

        $byDepartment = collect($response->json('data.breakdowns.by_department'));
        $this->assertSame(5, $byDepartment->firstWhere('code', 'ADM')['total']);
        $this->assertSame(1, $byDepartment->firstWhere('code', 'FIN')['total']);

        // Twelve months, newest last, and this month holds everything we made.
        $byMonth = collect($response->json('data.breakdowns.by_month'));
        $this->assertCount(12, $byMonth);
        $this->assertSame(now()->format('Y-m'), $byMonth->last()['month']);
        $this->assertSame(6, $byMonth->last()['created']);
        $this->assertSame(2, $byMonth->last()['completed']);
    }

    /** Narrowing the filters narrows every figure, not just the row list. */
    public function test_filters_narrow_the_kpis(): void
    {
        $this->buildFixture();

        $finance = Department::where('code', 'FIN')->firstOrFail();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/dashboard?department_id={$finance->id}")
            ->assertOk()
            ->assertJsonPath('data.kpis.total', 1)
            ->assertJsonPath('data.kpis.pending', 1)
            ->assertJsonPath('data.kpis.completed', 0)
            ->assertJsonPath('data.kpis.completion_rate', 0)
            // No completed work in this department, so there is no cycle time
            // to report — zero would be a lie.
            ->assertJsonPath('data.kpis.average_cycle_days', null);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/dashboard?status=cancelled')
            ->assertOk()
            ->assertJsonPath('data.kpis.total', 1)
            ->assertJsonPath('data.kpis.abandoned', 1);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/dashboard?date_to=2000-01-01')
            ->assertOk()
            ->assertJsonPath('data.kpis.total', 0)
            ->assertJsonPath('data.kpis.completion_rate', 0);
    }

    /**
     * Aggregates are cached, so the risk worth testing is staleness: a new
     * request must appear immediately, not five minutes later.
     */
    public function test_a_new_request_invalidates_the_cached_kpis(): void
    {
        $this->buildFixture();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/dashboard')
            ->assertJsonPath('data.kpis.total', 6);

        $this->request('new', overdue: false);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/dashboard')
            ->assertJsonPath('data.kpis.total', 7);
    }

    /** The report lists the same population its summary describes. */
    public function test_the_report_lists_filtered_rows_with_a_matching_summary(): void
    {
        $this->buildFixture();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/requests')
            ->assertOk()
            ->assertJsonPath('meta.total', 6)
            ->assertJsonPath('summary.total', 6)
            ->assertJsonPath('summary.pending', 3);

        $finance = Department::where('code', 'FIN')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/reports/requests?department_id={$finance->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary.total', 1)
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/requests?date_from=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_from');
    }

    /**
     * The Stage 24 done-when, second half: a filtered report exports.
     *
     * Signatures rather than parsed contents — an .xlsx that starts with `PK`
     * is a real zip container and a `%PDF` header is a real PDF, which is the
     * part that would break if a writer were misconfigured. Both formats are
     * exercised because they share nothing but the ReportDocument.
     */
    public function test_a_filtered_report_exports_as_xlsx_and_pdf(): void
    {
        $this->buildFixture();
        $admin = $this->admin();
        $finance = Department::where('code', 'FIN')->firstOrFail();

        $xlsx = $this->actingAs($admin, 'sanctum')
            ->get("/api/reports/requests/export?format=xlsx&department_id={$finance->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertStringContainsString('attachment;', $xlsx->headers->get('Content-Disposition'));
        $this->assertStringContainsString('requests-report-', $xlsx->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('PK', $xlsx->getContent());

        // Arabic explicitly: it is the default and the harder path — the PDF
        // writer has to subset an Arabic-capable font and shape the glyphs, and
        // a misconfigured mPDF throws rather than degrading quietly.
        $arabicPdf = $this->actingAs($admin, 'sanctum')
            ->get('/api/reports/requests/export?format=pdf&locale=ar')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $arabicPdf->getContent());

        $this->assertStringStartsWith(
            '%PDF',
            $this->actingAs($admin, 'sanctum')
                ->get('/api/reports/requests/export?format=pdf&locale=en')
                ->assertOk()
                ->getContent(),
        );

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/requests/export?format=docx')
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');
    }

    /**
     * Viewing the numbers and walking out with a file are separate grants:
     * `reports` seeds view to everyone but export only to R06/R07.
     */
    public function test_export_requires_the_export_permission_while_viewing_does_not(): void
    {
        $this->buildFixture();
        $reviewer = $this->userWithRole('R02');

        $this->actingAs($reviewer, 'sanctum')
            ->getJson('/api/reports/requests')
            ->assertOk();

        $this->actingAs($reviewer, 'sanctum')
            ->getJson('/api/reports/requests/export')
            ->assertForbidden();

        $this->actingAs($this->userWithRole('R06'), 'sanctum')
            ->get('/api/reports/requests/export')
            ->assertOk();
    }

    /** Stage 22 seeded `audit_log,export` and left it unused; Stage 24 uses it. */
    public function test_the_audit_log_exports_for_a_role_that_holds_the_export_grant(): void
    {
        $admin = $this->admin();

        // Something to export: this write is itself audited.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/departments', ['name_ar' => 'إدارة التقارير', 'code' => 'RPT'])
            ->assertCreated();

        $export = $this->actingAs($this->userWithRole('R07'), 'sanctum')
            ->get('/api/audit-logs/export?model=department&format=xlsx')
            ->assertOk();

        $this->assertStringContainsString('audit-log-', $export->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('PK', $export->getContent());

        // R02 can read the trail on screen but not take a copy of it.
        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->getJson('/api/audit-logs')
            ->assertOk();
        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->getJson('/api/audit-logs/export')
            ->assertForbidden();
    }

    /**
     * Six requests in the admin department plus one in finance:
     *   2 completed (cycle times of 4 and 5 days → average 4.5)
     *   1 cancelled, 1 overdue-and-open, 1 open, 1 in finance
     */
    private function buildFixture(): void
    {
        $this->completedRequest('final_approved', cycleDays: 4);
        $this->completedRequest('archived', cycleDays: 5, overdue: true);
        $this->request('cancelled', overdue: false);
        $this->request('in_review', overdue: true);
        $this->request('new', overdue: false);
        $this->request('new', overdue: false, departmentCode: 'FIN');
    }

    private function request(
        string $statusCode,
        bool $overdue,
        string $departmentCode = 'ADM',
        ?\DateTimeInterface $submittedAt = null,
    ): Request {
        $requestRecord = Request::create([
            'reference_number' => '2026-'.$departmentCode.'-'.fake()->unique()->numerify('######'),
            'title' => 'طلب اختبار التقارير',
            'department_id' => Department::where('code', $departmentCode)->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'reviewer_review')->value('id'),
            'created_by_user_id' => $this->admin()->id,
            'submitted_at' => $submittedAt ?? now(),
            'due_date' => now()->subDay()->toDateString(),
            'decision_grade' => 10,
        ]);

        if ($overdue) {
            // Not mass-assignable — the Stage 17 sweep is the only thing meant
            // to declare a breach, so it is set deliberately here too.
            $requestRecord->overdue_at = now()->subDay();
            $requestRecord->save();
        }

        return $requestRecord;
    }

    /** Cycle time is measured from submission to the first completing status. */
    private function completedRequest(string $statusCode, int $cycleDays, bool $overdue = false): Request
    {
        $submittedAt = now()->subDays($cycleDays);
        $requestRecord = $this->request($statusCode, $overdue, submittedAt: $submittedAt);

        RequestStatusHistory::create([
            'request_id' => $requestRecord->id,
            'to_status_id' => $requestRecord->status_id,
            'changed_by_user_id' => $this->admin()->id,
            'changed_at' => now(),
        ]);

        return $requestRecord;
    }

    private function admin(): User
    {
        return User::where('email', 'admin@abusaleem.test')->firstOrFail();
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
