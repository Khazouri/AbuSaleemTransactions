<?php

namespace App\Services\Performance;

use App\Models\Request;
use App\Models\Role;
use App\Models\WorkflowTransition;
use App\Services\EmployeeNoticeService;
use App\Services\ReportMetricsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stage 81 — [D] Appendix 10's ten مؤشرات الإنذار المبكر.
 *
 * **Every row names the party the file is waiting on, and that is the
 * appendix's own requirement rather than a nicety.** It closes with "ويجب أن
 * يركز التقرير الإداري على **سبب التعطل والجهة التي يتطلب منها الإجراء التالي**،
 * لا على مجرد عدد الأيام" — so an alert list that reported elapsed days and left
 * the reader to work out whose move it is would be the exact report the
 * appendix rules out.
 *
 * The responsible party is resolved from the **outbound `workflow_transitions`
 * rows WorkflowService itself enforces** — the same source Stage 71's
 * escalation ladder reads — so the alert and the button that would clear it can
 * never name different people. Appendix 17's own stored المسؤول الحالي field is
 * a different thing and stays Stage 83's; this reads the live rule rather than
 * introducing a second, unreconciled record of the same fact.
 *
 * All ten conditions are derived from state prior stages already write. None is
 * attested, and none is proxied off something that merely correlates.
 */
class EarlyWarningService
{
    /** Appendix 10's ten, in the appendix's own order. */
    public const WARNINGS = [
        'unusual_dwell' => ['ar' => 'بقيت لدى جهة واحدة مدة غير معتادة', 'en' => 'Held by one party for an unusual period'],
        'repeated_completion_returns' => ['ar' => 'أعيدت لاستكمال النواقص أكثر من مرة', 'en' => 'Returned for completion more than once'],
        'repeated_deferrals' => ['ar' => 'تأجلت من اللجنة أكثر من مرة', 'en' => 'Deferred by the committee more than once'],
        'approval_not_received' => ['ar' => 'لم يرد بشأنها اعتماد', 'en' => 'No approval has come back'],
        'approved_not_executed' => ['ar' => 'ورد اعتمادها ولم تنفذ', 'en' => 'Approved but not executed'],
        'executed_file_not_updated' => ['ar' => 'نفذت ولم تحدث في ملف الموظف', 'en' => 'Executed but the employee file was not updated'],
        'returned_by_approving_body' => ['ar' => 'أعيدت من جهة الاعتماد', 'en' => 'Returned by the approving body'],
        'employee_not_notified' => ['ar' => 'لم يتم إشعار صاحبها', 'en' => 'The employee has not been notified'],
        'approaching_legal_deadline' => ['ar' => 'اقتربت من مدة قانونية مؤثرة', 'en' => 'Approaching a binding legal deadline'],
        'open_appeal' => ['ar' => 'يوجد بشأنها تظلم مفتوح', 'en' => 'An appeal is open against it'],
    ];

    /**
     * Statuses after which nothing further is owed, so nothing can be late.
     *
     * Deliberately only these three. A `rejected`/`not_approved`/
     * `outside_jurisdiction` file is concluded as to its outcome but Art. 37
     * still requires it to be notified and closed, so it can legitimately
     * raise warning 8 or 10 and must stay in the population.
     */
    private const SETTLED_STATUSES = ['completed_closed', 'archived', 'cancelled'];

    /** The two levels Appendix 38 already calls late. */
    private const LATE_LEVELS = ['red', 'critical'];

    /** "اقتربت" is approaching, so the warning window opens at yellow. */
    private const APPROACHING_LEVELS = ['yellow', 'red', 'critical'];

    public function __construct(private readonly ReportMetricsService $metrics) {}

    /**
     * One row per request carrying at least one warning, worst first.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function alerts(array $filters = [], string $locale = 'ar', ?int $limit = null): array
    {
        $requests = $this->population($filters);

        if ($requests->isEmpty()) {
            return [];
        }

        $ids = $requests->pluck('id')->all();

        $shortfallRounds = $this->shortfallRounds($ids);
        $deferrals = $this->deferralRounds($ids);
        $openReferrals = $this->openReferrals($ids);
        $openReturns = $this->openReturns($ids);
        $notified = $this->notifiedRequestIds($ids);
        $openAppeals = $this->openAppeals($ids);
        $responsible = $this->responsibleParties($requests, $locale);

        $rows = [];

        foreach ($requests as $requestRecord) {
            $id = $requestRecord->getKey();
            $timeliness = $requestRecord->stageTimeliness();
            $level = $timeliness['level'] ?? null;
            $statusCode = (string) $requestRecord->status?->code;

            $fired = [];

            if (in_array($level, self::LATE_LEVELS, true)) {
                $fired[] = 'unusual_dwell';
            }

            if (($shortfallRounds[$id] ?? 0) > 1) {
                $fired[] = 'repeated_completion_returns';
            }

            if (($deferrals[$id] ?? 0) > 1) {
                $fired[] = 'repeated_deferrals';
            }

            if (in_array($id, $openReferrals, true)) {
                $fired[] = 'approval_not_received';
            }

            if ($statusCode === 'final_approved' && $requestRecord->executed_at === null) {
                $fired[] = 'approved_not_executed';
            }

            // Stage 76's own executor checklist is the record here: the
            // question was asked and answered, so an answer that is not "yes"
            // is a fact, not an absence.
            if ($requestRecord->executed_at !== null
                && ($requestRecord->execution_checklist['employee_file_updated'] ?? null) !== 'yes') {
                $fired[] = 'executed_file_not_updated';
            }

            if (in_array($id, $openReturns, true)) {
                $fired[] = 'returned_by_approving_body';
            }

            if ($this->owedANotice($requestRecord) && ! in_array($id, $notified, true)) {
                $fired[] = 'employee_not_notified';
            }

            if ($requestRecord->legallyTimeBound() && in_array($level, self::APPROACHING_LEVELS, true)) {
                $fired[] = 'approaching_legal_deadline';
            }

            if (in_array($id, $openAppeals, true)) {
                $fired[] = 'open_appeal';
            }

            if ($fired === []) {
                continue;
            }

            $rows[] = [
                'request_id' => $id,
                'reference_number' => $requestRecord->trackingNumber(),
                'subject' => $requestRecord->title,
                'stage' => $this->localName($requestRecord->currentStage, $locale),
                'status' => $this->localName($requestRecord->status, $locale),
                'elapsed_days' => $timeliness['elapsed_days'] ?? null,
                'timeliness_level' => $level,
                // Appendix 10's own closing requirement — see the class docblock.
                'responsible' => $responsible[$id] ?? null,
                'warnings' => array_map(fn (string $key) => [
                    'key' => $key,
                    'label' => self::WARNINGS[$key][$locale] ?? self::WARNINGS[$key]['ar'],
                ], $fired),
            ];
        }

        // Most-warnings first, then longest-waiting: the appendix's own point
        // is that the report should surface where work is actually stuck.
        usort($rows, fn (array $a, array $b) => [count($b['warnings']), $b['elapsed_days'] ?? 0]
            <=> [count($a['warnings']), $a['elapsed_days'] ?? 0]);

        return $limit === null ? $rows : array_slice($rows, 0, $limit);
    }

    /**
     * How many requests each warning currently fires on.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function summary(array $filters = [], string $locale = 'ar'): array
    {
        $counts = [];

        foreach ($this->alerts($filters, $locale) as $row) {
            foreach ($row['warnings'] as $warning) {
                $counts[$warning['key']] = ($counts[$warning['key']] ?? 0) + 1;
            }
        }

        $summary = [];
        $number = 0;

        foreach (self::WARNINGS as $key => $label) {
            $number++;
            $summary[] = [
                'key' => $key,
                'number' => $number,
                'label' => $label[$locale] ?? $label['ar'],
                'total' => $counts[$key] ?? 0,
            ];
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Request>
     */
    private function population(array $filters): Collection
    {
        return $this->metrics->query($filters)
            ->whereDoesntHave('status', fn ($query) => $query->whereIn('code', self::SETTLED_STATUSES))
            ->with([
                'status:id,code,name_ar,name_en',
                'currentStage:id,code,name_ar,name_en,order_no,target_days_min,target_days_max,responsible_role_id',
                'currentStage.responsibleRole:id,code,name_ar,name_en',
                // Not column-restricted: a latestOfMany subquery join collides
                // with a restricted select ("ambiguous column name") — the
                // gotcha Stage 52 recorded for latestStageLog and Stage 74 hit
                // again for latestLegalReview.
                'latestStageLog',
                'latestLegalReview',
            ])
            ->get();
    }

    /**
     * Stage 71's own resolution, reused: the roles on the non-exception
     * outbound rules for each request's current stage.
     *
     * @param  Collection<int, Request>  $requests
     * @return array<int, string|null>
     */
    private function responsibleParties(Collection $requests, string $locale): array
    {
        $stageIds = $requests->pluck('current_stage_id')->filter()->unique()->all();

        if ($stageIds === []) {
            return [];
        }

        $rules = WorkflowTransition::query()
            ->whereIn('from_stage_id', $stageIds)
            ->where('is_exception', false)
            ->get(['from_stage_id', 'request_type_id', 'required_role_id', 'requires_submitter_manager']);

        $roles = Role::query()
            ->whereIn('id', $rules->pluck('required_role_id')->filter()->unique()->all())
            ->get(['id', 'name_ar', 'name_en'])
            ->keyBy('id');

        $parties = [];

        foreach ($requests as $requestRecord) {
            $applicable = $rules->filter(fn (WorkflowTransition $rule) => $rule->from_stage_id === $requestRecord->current_stage_id
                && ($rule->request_type_id === null || $rule->request_type_id === $requestRecord->request_type_id));

            $names = $applicable
                ->pluck('required_role_id')
                ->filter()
                ->unique()
                ->map(fn ($roleId) => $this->localName($roles[$roleId] ?? null, $locale))
                ->filter()
                ->values();

            if ($applicable->contains(fn (WorkflowTransition $rule) => (bool) $rule->requires_submitter_manager)) {
                $names->push($locale === 'ar' ? 'الرئيس المباشر' : 'Direct manager');
            }

            // Falling back to the stage's own seeded responsible role rather
            // than reporting nobody: a stage with no outbound rule still has a
            // named owner, and "unknown" would be the one answer Appendix 10
            // explicitly refuses to accept.
            $parties[$requestRecord->getKey()] = $names->isNotEmpty()
                ? $names->unique()->implode(' / ')
                : $this->localName($requestRecord->currentStage?->responsibleRole, $locale);
        }

        return $parties;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function shortfallRounds(array $ids): array
    {
        return DB::table('request_status_history')
            ->join('request_statuses', 'request_statuses.id', '=', 'request_status_history.to_status_id')
            ->whereIn('request_status_history.request_id', $ids)
            ->whereIn('request_statuses.code', ['incomplete', 'completion_required'])
            ->select('request_status_history.request_id', DB::raw('COUNT(*) as rounds'))
            ->groupBy('request_status_history.request_id')
            ->pluck('rounds', 'request_id')
            ->map(fn ($rounds) => (int) $rounds)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function deferralRounds(array $ids): array
    {
        return DB::table('decisions')
            ->join('meeting_requests', 'meeting_requests.id', '=', 'decisions.meeting_request_id')
            ->whereIn('meeting_requests.request_id', $ids)
            ->where('decisions.outcome', 'defer')
            ->select('meeting_requests.request_id', DB::raw('COUNT(*) as rounds'))
            ->groupBy('meeting_requests.request_id')
            ->pluck('rounds', 'request_id')
            ->map(fn ($rounds) => (int) $rounds)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function openReferrals(array $ids): array
    {
        return DB::table('approval_referrals')
            ->whereIn('request_id', $ids)
            ->whereNull('result_received_at')
            ->distinct()
            ->pluck('request_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function openReturns(array $ids): array
    {
        return DB::table('approval_returns')
            ->whereIn('request_id', $ids)
            ->whereNull('resolved_at')
            ->distinct()
            ->pluck('request_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function openAppeals(array $ids): array
    {
        return DB::table('appeals')
            ->leftJoin('appeal_statuses', 'appeal_statuses.id', '=', 'appeals.appeal_status_id')
            ->whereIn('appeals.original_request_id', $ids)
            ->where(function ($query) {
                $query->whereNull('appeal_statuses.code')
                    ->orWhere('appeal_statuses.code', '!=', 'notified_closed');
            })
            ->distinct()
            ->pluck('appeals.original_request_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Which of these requests have a recorded Art. 101 notice.
     *
     * Read back from Laravel's own notifications rows, the same shape
     * RequestController's employee-notice register uses — decoded in PHP
     * rather than matched with a JSON path per id, so the query stays one
     * statement and portable across the MySQL and sqlite connections.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function notifiedRequestIds(array $ids): array
    {
        $notified = [];

        DB::table('notifications')
            ->where('data->event_type', 'request_notice')
            ->pluck('data')
            ->each(function ($payload) use (&$notified, $ids) {
                $decoded = json_decode((string) $payload, true);
                $id = (int) ($decoded['request_id'] ?? 0);

                if ($id > 0 && in_array($id, $ids, true)) {
                    $notified[$id] = true;
                }
            });

        return array_keys($notified);
    }

    /**
     * Whether the file has ever reached a state Art. 101 owes a notice for.
     *
     * Without this the warning would fire on every freshly submitted request,
     * which has not been notified because nothing notifiable has happened to
     * it yet — an alert that is true of everything tells the reader nothing.
     */
    private function owedANotice(Request $requestRecord): bool
    {
        return in_array((string) $requestRecord->status?->code, EmployeeNoticeService::notifyingStatusCodes(), true);
    }

    private function localName(mixed $model, string $locale): ?string
    {
        if ($model === null) {
            return null;
        }

        $preferred = $locale === 'ar' ? $model->name_ar : $model->name_en;
        $fallback = $locale === 'ar' ? $model->name_en : $model->name_ar;

        return ($preferred ?: $fallback) ?: null;
    }
}
