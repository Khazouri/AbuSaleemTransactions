<?php

namespace Tests\Feature;

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
use App\Services\Performance\CommitteeBoardService;
use App\Services\Performance\EarlyWarningService;
use App\Services\Performance\PerformanceIndicatorService;
use App\Services\Performance\TimeCardCompiler;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 81 — [D] Art. 106's indicators, Appendix 71's time card, Appendix 10's
 * warnings, Appendix 11's board and the three periodic reports.
 *
 * Every expected number is readable from the fixture that produced it rather
 * than from the service's own query, which is the only way an assertion about
 * an average proves anything.
 */
class PerformanceIndicatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    // ---------------------------------------------------------------- Appendix 71

    /** A file walked end to end reports every one of the ten segments. */
    public function test_the_time_card_measures_appendix_71s_ten_segments(): void
    {
        $requestRecord = $this->walkedRequest();

        $segments = app(TimeCardCompiler::class)->forRequest($requestRecord)->segments();

        // The fixture stamps each hop a known number of days apart; see
        // walkedRequest(). Reading these back is what proves the mapping, not
        // merely that something non-null came out.
        $this->assertSame(2.0, $segments['t1']);   // submitted → administrative_routing
        $this->assertSame(1.0, $segments['t2']);   // routing → requirements_check
        $this->assertSame(3.0, $segments['t3']);   // the قيد dwell
        $this->assertSame(4.0, $segments['t5']);   // legal review, in and out
        $this->assertSame(5.0, $segments['t6']);   // ready → the sitting
        $this->assertSame(2.0, $segments['t7']);   // sitting → minutes
        $this->assertSame(6.0, $segments['t8']);   // referral → result
        $this->assertSame(3.0, $segments['t9']);   // final approval → executed
        $this->assertSame(30.0, $segments['t10']); // submitted → closed
    }

    /**
     * T4 sums every shortfall round rather than reporting the first.
     *
     * A file returned twice was blocked twice, and Art. 19's استكمال loop is
     * explicitly repeatable — reporting only the first round would understate
     * exactly the bottleneck Art. 106 says this measures.
     */
    public function test_the_shortfall_segment_sums_every_round(): void
    {
        $requestRecord = $this->baseRequest();

        // Two rounds: 3 days, then 5.
        $this->history($requestRecord, 'incomplete', now()->subDays(40));
        $this->history($requestRecord, 'registered', now()->subDays(37));
        $this->history($requestRecord, 'completion_required', now()->subDays(20));
        $this->history($requestRecord, 'ready', now()->subDays(15));

        $segments = app(TimeCardCompiler::class)->forRequest($requestRecord)->segments();

        $this->assertSame(8.0, $segments['t4']);
    }

    /**
     * A segment whose far endpoint has not happened is null, never zero.
     *
     * Zero would read as instantaneous, and averaging it in would report
     * unstarted work as perfect performance.
     */
    public function test_an_unfinished_segment_is_null_rather_than_zero(): void
    {
        $requestRecord = $this->baseRequest();

        $segments = app(TimeCardCompiler::class)->forRequest($requestRecord)->segments();

        $this->assertNull($segments['t9']);  // never executed
        $this->assertNull($segments['t10']); // never closed
        $this->assertNull($segments['t4']);  // never short of anything
    }

    /** The card reaches the detail endpoint with [D]'s own wording. */
    public function test_the_time_card_reaches_the_request_detail_endpoint(): void
    {
        $requestRecord = $this->walkedRequest();

        $response = $this->actingAs(User::find($requestRecord->created_by_user_id), 'sanctum')
            ->getJson('/api/requests/'.$requestRecord->id)
            ->assertOk();

        $card = $response->json('data.time_card');

        $this->assertCount(10, $card);
        $this->assertSame(range(1, 10), array_column($card, 'number'));
        $this->assertSame('مدة فحص الاكتمال', $card[2]['label']);
        // Compared loosely: json_encode renders 3.0 as 3, so the wire value
        // is an int here even though the computation is a float.
        $this->assertEquals(3.0, $card[2]['days']);
    }

    // ---------------------------------------------------------------- Art. 106

    /** Art. 106's table has twelve rows; the endpoint returns exactly those. */
    public function test_the_endpoint_returns_art_106s_twelve_indicators_in_order(): void
    {
        $response = $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->getJson('/api/reports/performance/indicators')
            ->assertOk();

        $this->assertSame([
            'initial_examination_days', 'incomplete_rate', 'shortfall_completion_days',
            'ready_before_meeting_rate', 'requests_per_meeting', 'deferred_rate',
            'deferred_for_documents_rate', 'approval_days', 'execution_days',
            'returned_by_approving_body_rate', 'open_overdue_count', 'full_cycle_days',
        ], array_column($response->json('data'), 'key'));

        $this->assertSame(range(1, 12), array_column($response->json('data'), 'number'));
        // Art. 106 is a two-column table; dropping the purpose would invite
        // the indicator to be read as something else.
        $this->assertSame('قياس جودة تقديم الطلبات', $response->json('data.1.purpose'));
    }

    /** The five duration indicators are the mean of their own segment. */
    public function test_the_duration_indicators_average_the_matching_time_card_segment(): void
    {
        // Two files: one walked fully (t3 = 3 days), one with a 5-day check.
        $this->walkedRequest();

        $second = $this->baseRequest('طلب نقل');
        $this->stage($second, 'administrative_routing', 'requirements_check', now()->subDays(20));
        $this->stage($second, 'requirements_check', 'reviewer_review', now()->subDays(15));

        $values = app(PerformanceIndicatorService::class)->values([]);

        // (3 + 5) / 2
        $this->assertSame(4.0, $values['initial_examination_days']);
    }

    /**
     * نسبة الملفات الناقصة counts a file that has SINCE been completed.
     *
     * Art. 106 says this measures "جودة تقديم الطلبات" — a rate that falls the
     * moment a shortfall is cleared would measure nothing about how the request
     * was submitted, which is the same reading Stage 80's register 2 made.
     */
    public function test_the_incomplete_rate_counts_a_file_that_has_since_been_completed(): void
    {
        $short = $this->baseRequest('ناقص ثم اكتمل');
        $this->history($short, 'incomplete', now()->subDays(20));
        $this->history($short, 'registered', now()->subDays(18));
        $this->baseRequest('مكتمل من البداية');

        $values = app(PerformanceIndicatorService::class)->values([]);

        // One of two, even though neither is incomplete right now.
        $this->assertSame(50.0, $values['incomplete_rate']);
        $this->assertSame('registered', $short->refresh()->status->code);
    }

    /**
     * The deferral pair, and why the seventh indicator is answerable at all.
     *
     * Stage 74 structured Art. 34's deferral instead of leaving it as prose, so
     * "التأجيل بسبب نقص مستندات" reads a recorded field rather than guessing
     * from free text. Its denominator is the deferrals, not the population.
     */
    public function test_the_deferral_indicators_read_stage_74s_structured_decision(): void
    {
        $deferredForDocs = $this->baseRequest('مؤجل لنقص مستند');
        $this->deferral($deferredForDocs, requiredDocument: 'كشف الخدمة');

        $deferredForOther = $this->baseRequest('مؤجل لسبب آخر');
        $this->deferral($deferredForOther, requiredDocument: null);

        $this->baseRequest('لم يؤجل');
        $this->baseRequest('لم يؤجل أيضاً');

        $values = app(PerformanceIndicatorService::class)->values([]);

        $this->assertSame(50.0, $values['deferred_rate']);            // 2 of 4 requests
        $this->assertSame(50.0, $values['deferred_for_documents_rate']); // 1 of 2 deferrals
    }

    /**
     * The return rate's denominator is referrals, not decisions.
     *
     * A decision never sent for approval cannot be returned, so counting it
     * would dilute an indicator Art. 106 says measures the approving body's own
     * judgement of the minutes.
     */
    public function test_the_return_rate_is_measured_against_referrals(): void
    {
        $returned = $this->baseRequest('أعيد');
        $this->referral($returned);
        ApprovalReturn::create([
            'request_id' => $returned->id,
            'return_kind' => 'formal',
            'return_reason_code' => 'missing_signature',
            'return_note' => 'توقيع ناقص',
            'letter_number' => 'ص/1',
            'received_at' => now()->subDay(),
            'recorded_by_user_id' => $returned->created_by_user_id,
        ]);

        $clean = $this->baseRequest('اعتُمد');
        $this->referral($clean);

        // A decided file that was never referred: it must not enter either side.
        $this->baseRequest('لم يُحل للاعتماد');

        $values = app(PerformanceIndicatorService::class)->values([]);

        $this->assertSame(50.0, $values['returned_by_approving_body_rate']);
    }

    /** Only files flagged by the Stage 17 sweep AND still open are counted. */
    public function test_the_overdue_indicator_reuses_the_reports_predicate(): void
    {
        $open = $this->baseRequest('متأخر ومفتوح');
        $open->overdue_at = now()->subDay();
        $open->save();

        $closed = $this->baseRequest('متأخر لكنه أُغلق');
        $closed->overdue_at = now()->subDay();
        $closed->status_id = RequestStatus::where('code', 'completed_closed')->value('id');
        $closed->save();

        $values = app(PerformanceIndicatorService::class)->values([]);

        $this->assertSame(1, $values['open_overdue_count']);
    }

    /** An indicator with nothing to measure is null, not a confident zero. */
    public function test_indicators_are_null_when_there_is_nothing_to_measure(): void
    {
        $values = app(PerformanceIndicatorService::class)->values([]);

        $this->assertNull($values['incomplete_rate']);
        $this->assertNull($values['approval_days']);
        $this->assertNull($values['requests_per_meeting']);
        $this->assertSame(0, $values['open_overdue_count']);
    }

    // ---------------------------------------------------------------- Appendix 10

    /** Each of the ten conditions fires on its own, and a clean file fires none. */
    public function test_each_early_warning_fires_on_its_own_condition(): void
    {
        $service = app(EarlyWarningService::class);

        // 2 — returned for completion more than once.
        $twiceShort = $this->baseRequest('ناقص مرتين');
        $this->history($twiceShort, 'incomplete', now()->subDays(20));
        $this->history($twiceShort, 'completion_required', now()->subDays(10));

        // 3 — deferred more than once.
        $twiceDeferred = $this->baseRequest('مؤجل مرتين');
        $this->deferral($twiceDeferred, 'كشف');
        $this->deferral($twiceDeferred, 'كشف آخر');

        // 4 — referred for approval, nothing back.
        $awaiting = $this->baseRequest('بانتظار الاعتماد');
        ApprovalReferral::create([
            'request_id' => $awaiting->id,
            'referred_at' => now()->subDays(10),
            'letter_number' => 'ص/9',
            'referred_to_body' => 'عميد البلدية',
            'recorded_by_user_id' => $awaiting->created_by_user_id,
        ]);

        // 5 — approved but not executed.
        $notExecuted = $this->baseRequest('اعتُمد ولم ينفذ');
        $notExecuted->status_id = RequestStatus::where('code', 'final_approved')->value('id');
        $notExecuted->save();

        // 6 — executed, but the Stage 76 checklist says the file was not updated.
        $notUpdated = $this->baseRequest('نُفذ دون تحديث الملف');
        $notUpdated->executed_at = now()->subDay();
        $notUpdated->execution_checklist = ['employee_file_updated' => 'no'];
        $notUpdated->save();

        // 7 — an unresolved Stage 77 return.
        $returned = $this->baseRequest('أعيد من جهة الاعتماد');
        ApprovalReturn::create([
            'request_id' => $returned->id,
            'return_kind' => 'formal',
            'return_reason_code' => 'numeric_error',
            'return_note' => 'خطأ رقمي',
            'letter_number' => 'ص/2',
            'received_at' => now()->subDay(),
            'recorded_by_user_id' => $returned->created_by_user_id,
        ]);

        // 8 — reached a notifying state with no notice recorded.
        $unnotified = $this->baseRequest('لم يُشعر صاحبه');

        $fired = collect($service->alerts())->keyBy('request_id');

        $this->assertContains('repeated_completion_returns', $this->keysFor($fired, $twiceShort->id));
        $this->assertContains('repeated_deferrals', $this->keysFor($fired, $twiceDeferred->id));
        $this->assertContains('approval_not_received', $this->keysFor($fired, $awaiting->id));
        $this->assertContains('approved_not_executed', $this->keysFor($fired, $notExecuted->id));
        $this->assertContains('executed_file_not_updated', $this->keysFor($fired, $notUpdated->id));
        $this->assertContains('returned_by_approving_body', $this->keysFor($fired, $returned->id));
        $this->assertContains('employee_not_notified', $this->keysFor($fired, $unnotified->id));
    }

    /**
     * A file that has not reached a notifying state does NOT raise warning 8.
     *
     * Without that guard the alert would fire on every freshly submitted
     * request, and an alert true of everything tells the reader nothing.
     */
    public function test_a_file_owed_no_notice_yet_does_not_raise_the_notification_warning(): void
    {
        $fresh = $this->baseRequest('جديد');
        $fresh->status_id = RequestStatus::where('code', 'new')->value('id');
        $fresh->save();

        $alerts = collect(app(EarlyWarningService::class)->alerts())->keyBy('request_id');

        $this->assertNotContains('employee_not_notified', $this->keysFor($alerts, $fresh->id));
    }

    /**
     * Every alert names the party the file is waiting on.
     *
     * Appendix 10 closes by requiring exactly this: "يجب أن يركز التقرير
     * الإداري على سبب التعطل **والجهة التي يتطلب منها الإجراء التالي**، لا على
     * مجرد عدد الأيام".
     */
    public function test_every_alert_names_the_party_the_file_is_waiting_on(): void
    {
        $requestRecord = $this->baseRequest('بانتظار جهة');
        $this->history($requestRecord, 'incomplete', now()->subDays(20));
        $this->history($requestRecord, 'completion_required', now()->subDays(10));

        $alerts = app(EarlyWarningService::class)->alerts();

        $this->assertNotEmpty($alerts);
        foreach ($alerts as $alert) {
            $this->assertNotNull($alert['responsible'], 'an alert with no responsible party is the one answer Appendix 10 refuses');
        }
    }

    /** A settled file raises nothing — nothing further is owed on it. */
    public function test_a_closed_file_raises_no_warning(): void
    {
        $closed = $this->baseRequest('مغلق');
        $closed->status_id = RequestStatus::where('code', 'completed_closed')->value('id');
        $closed->closed_at = now()->subDay();
        $closed->save();

        $alerts = collect(app(EarlyWarningService::class)->alerts())->pluck('request_id');

        $this->assertNotContains($closed->id, $alerts);
    }

    /** The summary tallies all ten, including the ones firing on nothing. */
    public function test_the_warning_summary_lists_all_ten_conditions(): void
    {
        $response = $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->getJson('/api/reports/performance/warnings')
            ->assertOk();

        $summary = $response->json('summary');

        $this->assertCount(10, $summary);
        $this->assertSame(range(1, 10), array_column($summary, 'number'));
        $this->assertSame('بقيت لدى جهة واحدة مدة غير معتادة', $summary[0]['label']);
    }

    // ---------------------------------------------------------------- Appendix 11

    /** The dashboard reports Appendix 11's ten buckets, not Stage 32's funnel. */
    public function test_the_dashboard_reports_appendix_11s_board(): void
    {
        $ready = $this->baseRequest('جاهز');
        $ready->status_id = RequestStatus::where('code', 'ready')->value('id');
        $ready->save();

        $response = $this->actingAs($this->userWithRole('R03'), 'sanctum')
            ->getJson('/api/meetings/dashboard')
            ->assertOk();

        $board = $response->json('data.board');

        $this->assertCount(11, $board, 'ten buckets, with the seventh split in two as the appendix writes it');
        $this->assertSame([
            'new', 'under_review', 'awaiting_completion', 'ready', 'on_agenda',
            'deferred', 'awaiting_municipal_approval', 'awaiting_central_approval',
            'in_execution', 'overdue', 'closed',
        ], array_column($board, 'key'));

        $buckets = collect($board)->keyBy('key');
        $this->assertSame(1, $buckets['ready']['total']);
        // Bucket 1 is period-bounded and bucket 9 cross-cuts the rest; the
        // payload says so rather than letting the screen imply a partition.
        $this->assertSame('period', $buckets['new']['scope']);
        $this->assertSame('cross_cutting', $buckets['overdue']['scope']);
        $this->assertSame('live', $buckets['ready']['scope']);

        $this->assertNull($response->json('data.funnel'), 'Stage 32 funnel replaced, not kept alongside');
    }

    /** Bucket 9 counts a late file that is also counted in its own live bucket. */
    public function test_the_overdue_bucket_cross_cuts_the_others(): void
    {
        $late = $this->baseRequest('متأخر');
        $late->status_id = RequestStatus::where('code', 'ready')->value('id');
        $late->overdue_at = now()->subDay();
        $late->save();

        $board = collect(app(CommitteeBoardService::class)->board())->keyBy('key');

        $this->assertSame(1, $board['ready']['total']);
        $this->assertSame(1, $board['overdue']['total']);
    }

    // ---------------------------------------------------------------- Arts. 107, 39, 40

    /** Each of the three reports returns its own shape. */
    public function test_the_three_periodic_reports_return_their_own_sections(): void
    {
        $this->walkedRequest();
        $reader = $this->userWithRole('R01');

        foreach (['periodic' => 'Art. 107', 'monthly' => 'Appendix 39', 'annual' => 'Appendix 40'] as $key => $source) {
            $response = $this->actingAs($reader, 'sanctum')
                ->getJson('/api/reports/performance/periodic/'.$key)
                ->assertOk();

            $this->assertSame($source, $response->json('data.source'));
            $this->assertNotEmpty($response->json('data.sections'));
        }
    }

    /**
     * Appendix 39's أسباب التعطيل is derived, not attested.
     *
     * Its seven named categories map onto this system's statuses, so the report
     * states where work is actually stuck rather than asking for an estimate.
     */
    public function test_the_monthly_report_derives_appendix_39s_seven_delay_causes(): void
    {
        $stuck = $this->baseRequest('عالق لدى الموظف');
        $stuck->status_id = RequestStatus::where('code', 'incomplete')->value('id');
        $stuck->save();

        $response = $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->getJson('/api/reports/performance/periodic/monthly')
            ->assertOk();

        $blockers = collect($response->json('data.sections'))->firstWhere('title', 'أسباب التعطيل');

        $this->assertCount(7, $blockers['items']);
        $this->assertSame('انتظار موظف', $blockers['items'][0]['label']);
        $this->assertSame(1, $blockers['items'][0]['value']);
    }

    /**
     * The narrative sections are named rather than fabricated.
     *
     * Art. 107's توصيات, Appendix 39's ملاحظات and Appendix 40's item 10 are the
     * rapporteur's writing or have no structured source; naming them lets a
     * reader see what is missing instead of assuming the report is whole.
     */
    public function test_the_reports_name_the_sections_they_cannot_compute(): void
    {
        $response = $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->getJson('/api/reports/performance/periodic/annual')
            ->assertOk();

        $this->assertContains('أكثر أسباب النقص', $response->json('data.narrative'));
        $this->assertContains('توصيات تطوير التشريعات أو الإجراءات', $response->json('data.narrative'));
    }

    /** A URL naming a report that does not exist is a 404. */
    public function test_an_unknown_periodic_report_is_not_found(): void
    {
        $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->getJson('/api/reports/performance/periodic/quarterly')
            ->assertNotFound();
    }

    /** The export reuses Stage 24's writers, in both formats. */
    public function test_a_periodic_report_exports_in_both_formats(): void
    {
        $this->walkedRequest();
        $exporter = $this->userWithRole('R06');

        $xlsx = $this->actingAs($exporter, 'sanctum')
            ->get('/api/reports/performance/periodic/monthly/export?format=xlsx')
            ->assertOk();
        $this->assertSame('PK', substr($xlsx->getContent(), 0, 2));

        $pdf = $this->actingAs($exporter, 'sanctum')
            ->get('/api/reports/performance/periodic/annual/export?format=pdf&locale=en')
            ->assertOk();
        $this->assertSame('%PDF', substr($pdf->getContent(), 0, 4));
    }

    /** Reading the indicators is broad; carrying a report out as a file is not. */
    public function test_export_is_refused_without_the_export_grant(): void
    {
        $reader = $this->userWithRole('R01');

        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/reports/performance/indicators')
            ->assertOk();

        $this->actingAs($reader, 'sanctum')
            ->get('/api/reports/performance/periodic/monthly/export')
            ->assertForbidden();
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * A request walked through every measurable segment, each hop a known
     * number of days apart so the assertions above can be read off it.
     */
    private function walkedRequest(): Request
    {
        $requestRecord = $this->baseRequest();
        $requestRecord->submitted_at = now()->subDays(30);
        $requestRecord->executed_at = now()->subDays(2);
        $requestRecord->closed_at = now();
        $requestRecord->save();

        // T1 = 2, T2 = 1, T3 = 3.
        $this->stage($requestRecord, 'direct_manager_review', 'administrative_routing', now()->subDays(28));
        $this->stage($requestRecord, 'administrative_routing', 'requirements_check', now()->subDays(27));
        $this->stage($requestRecord, 'requirements_check', 'reviewer_review', now()->subDays(24));

        // T5 = 4.
        $this->history($requestRecord, 'under_legal_review', now()->subDays(23));
        $this->history($requestRecord, 'ready', now()->subDays(19));

        // T6 = 5, T7 = 2.
        [$meeting] = $this->fixtureMeeting($requestRecord, now()->subDays(14));
        MeetingMinutes::create([
            'meeting_id' => $meeting->id,
            'minutes_number' => 'PM-MIN/2026/01',
            'content' => [],
            'status' => 'approved',
            'generated_at' => now()->subDays(12),
        ]);

        // T8 = 6.
        $this->referral($requestRecord, now()->subDays(11), now()->subDays(5));

        // T9 = 3.
        $this->history($requestRecord, 'final_approved', now()->subDays(5));

        return $requestRecord->refresh();
    }

    private function baseRequest(string $title = 'طلب ترقية'): Request
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
            'submitted_at' => now()->subDays(30),
        ]);
    }

    private function stage(Request $requestRecord, string $from, string $to, $at): void
    {
        $requestRecord->stageLogs()->create([
            'from_stage_id' => WorkflowStage::where('code', $from)->value('id'),
            'to_stage_id' => WorkflowStage::where('code', $to)->value('id'),
            'action' => 'forward',
            'acted_by_user_id' => $requestRecord->created_by_user_id,
            'acted_at' => $at,
        ]);
    }

    private function history(Request $requestRecord, string $to, $at): void
    {
        RequestStatusHistory::create([
            'request_id' => $requestRecord->id,
            'to_status_id' => RequestStatus::where('code', $to)->value('id'),
            'changed_by_user_id' => $requestRecord->created_by_user_id,
            'changed_at' => $at,
        ]);
    }

    private function referral(Request $requestRecord, $referredAt = null, $receivedAt = null): ApprovalReferral
    {
        return ApprovalReferral::create([
            'request_id' => $requestRecord->id,
            'referred_at' => $referredAt ?? now()->subDays(10),
            'letter_number' => 'ص/2026/'.$requestRecord->id,
            'referred_to_body' => 'عميد البلدية',
            'recorded_by_user_id' => $requestRecord->created_by_user_id,
            'result_received_at' => $receivedAt ?? now()->subDays(4),
            'result_outcome' => 'approved',
            'result_recorded_by_user_id' => $requestRecord->created_by_user_id,
            'result_recorded_at' => $receivedAt ?? now()->subDays(4),
        ]);
    }

    private function deferral(Request $requestRecord, ?string $requiredDocument): Decision
    {
        [, $agendaItem] = $this->fixtureMeeting($requestRecord, now()->subDays(7));

        return Decision::create([
            'meeting_request_id' => $agendaItem->id,
            'outcome' => 'defer',
            'instrument' => 'decision',
            'decision_subject' => 'تأجيل',
            'decision_operative' => 'قررت اللجنة تأجيل الموضوع لاستكمال ما هو مطلوب.',
            'deferral_reason' => 'نقص',
            'deferral_required_completion' => 'استكمال المستند',
            'deferral_responsible_body' => 'قسم شؤون الموظفين',
            'deferral_required_document' => $requiredDocument,
            'decided_by_user_id' => $requestRecord->created_by_user_id,
            'decided_at' => now()->subDays(7),
        ]);
    }

    /** @return array{0: Meeting, 1: MeetingRequest} */
    private function fixtureMeeting(Request $requestRecord, $scheduledAt): array
    {
        $committee = Committee::firstOrCreate(['name_ar' => 'لجنة شؤون الموظفين']);
        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'meeting_number' => 'PM-MTG/2026/'.str_pad((string) (Meeting::count() + 1), 2, '0', STR_PAD_LEFT),
            'title' => 'الاجتماع',
            'scheduled_at' => $scheduledAt,
            'created_by_user_id' => $requestRecord->created_by_user_id,
        ]);
        $agendaItem = MeetingRequest::create([
            'meeting_id' => $meeting->id,
            'request_id' => $requestRecord->id,
            'agenda_order' => 1,
        ]);

        return [$meeting, $agendaItem];
    }

    /** @return list<string> */
    private function keysFor($alerts, int $requestId): array
    {
        $row = $alerts[$requestId] ?? null;

        return $row === null ? [] : array_column($row['warnings'], 'key');
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'department_id' => Department::query()->where('code', 'ADM')->value('id'),
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));

        // Membership gate — the meetings dashboard is one of the screens hidden
        // from anyone with no committee seat, so an actor in this file holds
        // one. It changes no indicator: the committee has no meetings.
        Committee::firstOrCreate(['name_ar' => 'لجنة شؤون الموظفين'])
            ->members()->firstOrCreate(['user_id' => $user->id]);

        return $user;
    }
}
