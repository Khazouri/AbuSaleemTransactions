<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\ApprovalReferral;
use App\Models\ApprovalReturn;
use App\Models\Committee;
use App\Models\Decision;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\MeetingRequest;
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

/**
 * Stage 80 — [D] Art. 98's twelve official registers.
 *
 * One fixture walks a single file through intake, a نواقص loop, a meeting, a
 * decision, a deferral, a referral, an execution and a closure, so every
 * register can be asserted against a population whose expected contents are
 * readable from the fixture rather than from the register's own query.
 */
class RegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** Art. 98 names twelve, in order, and the catalogue is that list. */
    public function test_the_catalogue_lists_art_98s_twelve_registers_in_order(): void
    {
        $response = $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->getJson('/api/registers')
            ->assertOk();

        $codes = array_column($response->json('data'), 'code');

        $this->assertSame([
            'incoming', 'incomplete', 'meetings', 'agenda', 'minutes', 'decisions',
            'approval_referrals', 'approval_returns', 'execution', 'appeals',
            'deferred', 'closure',
        ], $codes);
        $this->assertSame(range(1, 12), array_column($response->json('data'), 'number'));
        // Every register declares its own columns, so one generic table on the
        // SPA can render all twelve.
        foreach ($response->json('data') as $register) {
            $this->assertNotEmpty($register['columns']);
        }
    }

    /** Register 1 carries both of Stage 70's numbers, which is Art. 99 made visible. */
    public function test_the_incoming_register_carries_both_numbers(): void
    {
        $requestRecord = $this->fixtureRequest();

        $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->getJson('/api/registers/incoming')
            ->assertOk()
            ->assertJsonPath('data.0.reference_number', $requestRecord->reference_number)
            ->assertJsonPath('data.0.intake_receipt_number', $requestRecord->intake_receipt_number)
            ->assertJsonPath('data.0.title', 'طلب ترقية')
            ->assertJsonPath('register.code', 'incoming')
            ->assertJsonPath('meta.total', 1);
    }

    /**
     * The نواقص register is built on status history, so a shortfall stays in
     * the register after the file has been completed — which is what makes it a
     * register rather than a worklist.
     */
    public function test_the_incomplete_register_keeps_a_shortfall_that_has_since_been_completed(): void
    {
        $requestRecord = $this->fixtureRequest();
        $officer = $this->userWithRole('R02');

        RequestStatusHistory::create([
            'request_id' => $requestRecord->id,
            'from_status_id' => RequestStatus::where('code', 'in_review')->value('id'),
            'to_status_id' => RequestStatus::where('code', 'incomplete')->value('id'),
            'reason' => 'ينقص كشف الخدمة.',
            'changed_by_user_id' => $officer->id,
            'changed_at' => now()->subDays(10),
        ]);

        // The file has since moved on — its current status is not a shortfall.
        $requestRecord->update(['status_id' => RequestStatus::where('code', 'registered')->value('id')]);

        $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->getJson('/api/registers/incomplete')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reference_number', $requestRecord->reference_number)
            ->assertJsonPath('data.0.reason', 'ينقص كشف الخدمة.')
            ->assertJsonPath('data.0.recorded_by', $officer->name)
            ->assertJsonPath('data.0.still_incomplete', 'لا');
    }

    /**
     * Register 6 is Appendix 12's thirteen columns, and its approval /
     * execution / closure trio is read from real state rather than attested.
     */
    public function test_the_decisions_register_carries_appendix_12s_thirteen_columns(): void
    {
        $requestRecord = $this->fixtureRequest();
        [$meeting, $agendaItem] = $this->fixtureMeeting($requestRecord);
        $recorder = $this->userWithRole('R03');

        Decision::create([
            'meeting_request_id' => $agendaItem->id,
            'decision_number' => 'PM-DEC/2026/001',
            'outcome' => 'approve',
            'instrument' => 'decision',
            'decision_subject' => 'الترقية إلى الدرجة الثامنة',
            'decision_operative' => 'قررت اللجنة الموافقة على الترقية.',
            'decided_by_user_id' => $recorder->id,
            'decided_at' => now()->subDays(5),
        ]);

        // The approving body answered, and the file was executed and closed.
        ApprovalReferral::create([
            'request_id' => $requestRecord->id,
            'referred_at' => now()->subDays(4),
            'letter_number' => 'ص/2026/118',
            'referred_to_body' => 'عميد البلدية',
            'result_outcome' => 'approved',
            'result_received_at' => now()->subDays(2),
            'approval_decision_number' => 'ق/2026/77',
            'recorded_by_user_id' => $recorder->id,
        ]);
        $requestRecord->update([
            'executed_at' => now()->subDay(),
            'closed_at' => now(),
            'closure' => ['final_result_code' => 'executed', 'approving_body' => 'عميد البلدية'],
        ]);

        $response = $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->getJson('/api/registers/decisions')
            ->assertOk()
            ->assertJsonPath('data.0.decision_number', 'PM-DEC/2026/001')
            ->assertJsonPath('data.0.meeting_number', $meeting->meeting_number)
            ->assertJsonPath('data.0.reference_number', $requestRecord->reference_number)
            ->assertJsonPath('data.0.operative', 'قررت اللجنة الموافقة على الترقية.')
            ->assertJsonPath('data.0.approving_body', 'عميد البلدية')
            ->assertJsonPath('data.0.final_decision_number', 'ق/2026/77')
            ->assertJsonPath('data.0.execution_status', 'منفذة')
            ->assertJsonPath('data.0.closure_status', 'مقفلة');

        // Appendix 12 names thirteen columns and this register has exactly them.
        $this->assertCount(13, $response->json('columns'));
    }

    /** Register 11 reads the decision, not the status — Appendix 29's own point. */
    public function test_the_deferred_register_carries_art_34s_five_fields(): void
    {
        $requestRecord = $this->fixtureRequest();
        [, $agendaItem] = $this->fixtureMeeting($requestRecord);

        Decision::create([
            'meeting_request_id' => $agendaItem->id,
            'outcome' => 'defer',
            'instrument' => 'decision',
            'decision_subject' => 'الترقية',
            'decision_operative' => 'قررت اللجنة التأجيل.',
            'deferral_reason' => 'نقص في المستندات المؤيدة.',
            'deferral_required_completion' => 'تقديم كشف الخدمة المعتمد.',
            'deferral_responsible_body' => 'قسم شؤون الموظفين',
            'deferral_required_document' => 'كشف خدمة',
            'deferral_legal_period' => '30 يوماً',
            'decided_by_user_id' => $this->userWithRole('R03')->id,
            'decided_at' => now()->subDays(3),
        ]);

        $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->getJson('/api/registers/deferred')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.deferral_reason', 'نقص في المستندات المؤيدة.')
            ->assertJsonPath('data.0.required_completion', 'تقديم كشف الخدمة المعتمد.')
            ->assertJsonPath('data.0.responsible_body', 'قسم شؤون الموظفين')
            ->assertJsonPath('data.0.required_document', 'كشف خدمة')
            ->assertJsonPath('data.0.legal_period', '30 يوماً');
    }

    /**
     * Each register lists only its own population — a register is a named view,
     * so one whose source is empty must be empty rather than falling back.
     */
    public function test_each_register_lists_only_its_own_population(): void
    {
        $requestRecord = $this->fixtureRequest();
        [$meeting, $agendaItem] = $this->fixtureMeeting($requestRecord);
        $actor = $this->userWithRole('R02');

        MeetingMinutes::create([
            'meeting_id' => $meeting->id,
            'minutes_number' => 'PM-MIN/2026/01',
            'content' => [],
            'status' => MeetingMinutes::STATUS_APPROVED,
            'generated_by_user_id' => $actor->id,
            'generated_at' => now()->subDays(4),
            'approved_at' => now()->subDays(3),
        ]);
        ApprovalReturn::create([
            'request_id' => $requestRecord->id,
            'return_kind' => ApprovalReturn::KIND_FORMAL,
            'return_reason_code' => 'missing_signature',
            'return_note' => 'ينقص توقيع.',
            'received_at' => now()->subDays(2),
            'recorded_by_user_id' => $actor->id,
        ]);
        Appeal::create([
            'appellant_user_id' => $requestRecord->created_by_user_id,
            'original_request_id' => $requestRecord->id,
            'appeal_status_id' => AppealStatus::where('code', 'submitted')->value('id'),
            'known_at' => now()->subDays(6),
            'appeal_reasons' => 'خطأ في تقدير الأقدمية.',
            'final_request' => 'إعادة النظر في الترقية.',
        ]);

        $reader = $this->userWithRole('R01');

        // Populated by the fixture above.
        foreach (['incoming' => 1, 'meetings' => 1, 'agenda' => 1, 'minutes' => 1,
            'approval_returns' => 1, 'appeals' => 1] as $code => $expected) {
            $this->actingAs($reader, 'sanctum')
                ->getJson("/api/registers/{$code}")
                ->assertOk()
                ->assertJsonPath('meta.total', $expected);
        }

        // Register 7 names the file it belongs to — asserted explicitly
        // because a belongsTo whose foreign key is derived from the method
        // name silently resolves to null, which reads as an empty column
        // rather than as an error.
        ApprovalReferral::create([
            'request_id' => $requestRecord->id,
            'referred_at' => now()->subDays(2),
            'letter_number' => 'ص/2026/9',
            'referred_to_body' => 'عميد البلدية',
            'recorded_by_user_id' => $actor->id,
        ]);
        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/registers/approval_referrals')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reference_number', $requestRecord->reference_number)
            ->assertJsonPath('data.0.title', $requestRecord->title);

        // Genuinely empty, because nothing in the fixture reaches them.
        foreach (['incomplete', 'decisions', 'execution', 'deferred', 'closure'] as $code) {
            $this->actingAs($reader, 'sanctum')
                ->getJson("/api/registers/{$code}")
                ->assertOk()
                ->assertJsonPath('meta.total', 0);
        }

        $this->assertSame($agendaItem->meeting_id, $meeting->id);
    }

    /** The date and search filters narrow a register the same way for all twelve. */
    public function test_the_shared_filters_narrow_a_register(): void
    {
        $first = $this->fixtureRequest();
        $second = $this->fixtureRequest('طلب نقل');
        $second->update(['submitted_at' => now()->subYear()]);

        $reader = $this->userWithRole('R01');

        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/registers/incoming?date_from='.now()->subMonth()->toDateString())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reference_number', $first->reference_number);

        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/registers/incoming?search=نقل')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'طلب نقل');
    }

    /** A URL naming a register that does not exist is a 404, not an empty table. */
    public function test_an_unknown_register_is_not_found(): void
    {
        $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->getJson('/api/registers/precedents')
            ->assertNotFound();
    }

    /** The export reuses Stage 24's writers, in both formats and both locales. */
    public function test_a_register_exports_in_both_formats(): void
    {
        $this->fixtureRequest();
        $exporter = $this->userWithRole('R06');

        $xlsx = $this->actingAs($exporter, 'sanctum')
            ->get('/api/registers/incoming/export?format=xlsx')
            ->assertOk();
        $this->assertSame('PK', substr($xlsx->getContent(), 0, 2));

        $pdf = $this->actingAs($exporter, 'sanctum')
            ->get('/api/registers/incoming/export?format=pdf&locale=en')
            ->assertOk();
        $this->assertSame('%PDF', substr($pdf->getContent(), 0, 4));
    }

    /** Reading a register is broad; carrying one out as a file is not. */
    public function test_export_is_refused_without_the_export_grant(): void
    {
        $this->fixtureRequest();

        $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->getJson('/api/registers/incoming')
            ->assertOk();

        $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->get('/api/registers/incoming/export')
            ->assertForbidden();
    }

    private function fixtureRequest(string $title = 'طلب ترقية'): Request
    {
        $creator = $this->userWithRole('R01');
        $sequence = Request::count() + 1;

        return Request::create([
            'reference_number' => 'PM-COM/2026/'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'intake_receipt_number' => 'PM-RCV/2026/'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'title' => $title,
            'department_id' => Department::query()->where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::query()->value('id'),
            'status_id' => RequestStatus::where('code', 'registered')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'requirements_check')->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now()->subWeek(),
        ]);
    }

    /** @return array{0: Meeting, 1: MeetingRequest} */
    private function fixtureMeeting(Request $requestRecord): array
    {
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'meeting_number' => 'PM-MTG/2026/01',
            'title' => 'الاجتماع الأول',
            'scheduled_at' => now()->subDays(6),
            'created_by_user_id' => $requestRecord->created_by_user_id,
        ]);
        $agendaItem = MeetingRequest::create([
            'meeting_id' => $meeting->id,
            'request_id' => $requestRecord->id,
            'agenda_order' => 1,
        ]);

        return [$meeting, $agendaItem];
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'department_id' => Department::query()->where('code', 'ADM')->value('id'),
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));

        return $user;
    }
}
