<?php

namespace App\Services\Performance;

use App\Models\Request;
use App\Services\ReportMetricsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stage 81 — [D] Art. 106's twelve مؤشرات الأداء الأساسية.
 *
 * **Twelve, not thirteen.** STAGE_PLAN's own heading calls these "the thirteen
 * official KPIs"; the verbatim Art. 106 table has twelve rows. The
 * transcription is the authority and the paraphrase is not — the same call
 * Stage 75 made when STAGE_PLAN described Appendix 47 as a thirteen-point
 * audit and the appendix itself listed twelve.
 *
 * **Five of the twelve are averages of an Appendix 71 segment, and they read it
 * rather than re-deriving it.** متوسط مدة الفحص الأولي is T3, متوسط مدة استكمال
 * النواقص is T4, متوسط مدة الاعتماد is T8, متوسط مدة التنفيذ بعد الاعتماد is
 * T9, and متوسط الدورة الكاملة is T10. Computing them here independently would
 * give the per-request card and the municipality-wide average two derivations
 * of one fact, free to disagree about the same file.
 *
 * Each indicator carries [D]'s own wording **and its own stated purpose**,
 * because Art. 106's table is two columns: an indicator whose purpose is
 * dropped invites someone to read "نسبة الملفات الناقصة" as a fault rate rather
 * than as "قياس جودة تقديم الطلبات", which is what the article says it measures.
 *
 * A value of **null means not measurable yet**, never zero — an average over a
 * population where nothing has reached the far end of the segment is absent,
 * and printing 0 would read as instant, perfect performance.
 */
class PerformanceIndicatorService
{
    /** Art. 106's own two columns, in the article's own order. */
    private const INDICATORS = [
        'initial_examination_days' => [
            'ar' => 'متوسط مدة الفحص الأولي', 'en' => 'Average initial examination time',
            'purpose_ar' => 'قياس سرعة استلام وتجهيز الملفات', 'purpose_en' => 'Speed of receiving and preparing files',
            'unit' => 'days',
        ],
        'incomplete_rate' => [
            'ar' => 'نسبة الملفات الناقصة', 'en' => 'Incomplete file rate',
            'purpose_ar' => 'قياس جودة تقديم الطلبات', 'purpose_en' => 'Quality of request submission',
            'unit' => 'percent',
        ],
        'shortfall_completion_days' => [
            'ar' => 'متوسط مدة استكمال النواقص', 'en' => 'Average shortfall completion time',
            'purpose_ar' => 'كشف نقاط التعطل', 'purpose_en' => 'Exposing bottlenecks',
            'unit' => 'days',
        ],
        'ready_before_meeting_rate' => [
            'ar' => 'نسبة الملفات الجاهزة قبل الاجتماع', 'en' => 'Files ready before the sitting',
            'purpose_ar' => 'قياس كفاءة المقرر', 'purpose_en' => 'Rapporteur efficiency',
            'unit' => 'percent',
        ],
        'requests_per_meeting' => [
            'ar' => 'عدد المعاملات بكل اجتماع', 'en' => 'Requests per sitting',
            'purpose_ar' => 'قياس حجم العمل', 'purpose_en' => 'Workload',
            'unit' => 'count',
        ],
        'deferred_rate' => [
            'ar' => 'نسبة المعاملات المؤجلة', 'en' => 'Deferred request rate',
            'purpose_ar' => 'قياس جودة التحضير', 'purpose_en' => 'Quality of preparation',
            'unit' => 'percent',
        ],
        'deferred_for_documents_rate' => [
            'ar' => 'نسبة التأجيل بسبب نقص مستندات', 'en' => 'Deferrals caused by missing documents',
            'purpose_ar' => 'قياس جودة الفحص السابق', 'purpose_en' => 'Quality of the prior examination',
            'unit' => 'percent',
        ],
        'approval_days' => [
            'ar' => 'متوسط مدة الاعتماد', 'en' => 'Average approval time',
            'purpose_ar' => 'قياس زمن المرحلة التالية للجنة', 'purpose_en' => 'Time of the stage after the committee',
            'unit' => 'days',
        ],
        'execution_days' => [
            'ar' => 'متوسط مدة التنفيذ بعد الاعتماد', 'en' => 'Average execution time after approval',
            'purpose_ar' => 'قياس كفاءة الموارد البشرية', 'purpose_en' => 'HR efficiency',
            'unit' => 'days',
        ],
        'returned_by_approving_body_rate' => [
            'ar' => 'نسبة القرارات المعادة من جهة الاعتماد', 'en' => 'Decisions returned by the approving body',
            'purpose_ar' => 'قياس جودة المحاضر والقرارات', 'purpose_en' => 'Quality of minutes and decisions',
            'unit' => 'percent',
        ],
        'open_overdue_count' => [
            'ar' => 'عدد المعاملات المفتوحة المتأخرة', 'en' => 'Open overdue requests',
            'purpose_ar' => 'قياس تراكم العمل', 'purpose_en' => 'Work accumulation',
            'unit' => 'count',
        ],
        'full_cycle_days' => [
            'ar' => 'متوسط الدورة الكاملة للمعاملة', 'en' => 'Average full request cycle',
            'purpose_ar' => 'قياس الأداء المؤسسي الكلي', 'purpose_en' => 'Overall institutional performance',
            'unit' => 'days',
        ],
    ];

    /** Both ends of Art. 19's loop — see RequestTimeCard for why both. */
    private const SHORTFALL_STATUSES = ['incomplete', 'completion_required'];

    public function __construct(
        private readonly ReportMetricsService $metrics,
        private readonly TimeCardCompiler $timeCards,
    ) {}

    /**
     * The twelve, in Art. 106's order, each with its value and its purpose.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function indicators(array $filters, string $locale = 'ar'): array
    {
        $values = $this->values($filters);
        $rows = [];
        $number = 0;

        foreach (self::INDICATORS as $key => $definition) {
            $number++;
            $rows[] = [
                'key' => $key,
                'number' => $number,
                'label' => $definition[$locale] ?? $definition['ar'],
                'purpose' => $definition['purpose_'.$locale] ?? $definition['purpose_ar'],
                'unit' => $definition['unit'],
                'value' => $values[$key],
            ];
        }

        return $rows;
    }

    /**
     * The raw numbers, cached on ReportMetricsService's own generation counter.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, float|int|null>
     */
    public function values(array $filters): array
    {
        return $this->metrics->remember('art106', $filters, function () use ($filters) {
            $population = $this->metrics->query($filters);
            $total = (clone $population)->count();

            // One batched pass over the population, then five averages read off
            // it — the alternative is five separate history scans computing
            // overlapping subsets of the same durations.
            $segments = $this->segmentAverages($filters);
            $deferrals = $this->deferralCounts($filters);
            $agenda = $this->agendaReadiness($filters);

            return [
                'initial_examination_days' => $segments['t3'],
                'incomplete_rate' => $this->rate($this->everIncompleteCount($filters), $total),
                'shortfall_completion_days' => $segments['t4'],
                'ready_before_meeting_rate' => $this->rate($agenda['ready_before'], $agenda['items']),
                'requests_per_meeting' => $agenda['meetings'] > 0
                    ? round($agenda['items'] / $agenda['meetings'], 1)
                    : null,
                'deferred_rate' => $this->rate($deferrals['requests_deferred'], $total),
                // Denominator is the deferrals themselves, not the whole
                // population: Art. 106 calls this "نسبة التأجيل بسبب نقص
                // مستندات" — the share OF deferrals with that cause.
                'deferred_for_documents_rate' => $this->rate($deferrals['for_documents'], $deferrals['deferrals']),
                'approval_days' => $segments['t8'],
                'execution_days' => $segments['t9'],
                'returned_by_approving_body_rate' => $this->approvalReturnRate($filters),
                'open_overdue_count' => $this->metrics->overdueQuery($filters)->count(),
                'full_cycle_days' => $segments['t10'],
            ];
        });
    }

    /**
     * Mean of each Appendix 71 segment across the population, nulls excluded.
     *
     * Excluded rather than zeroed: a file that has not been executed has no
     * execution duration, and counting it as zero days would report the
     * unstarted work as instantaneous.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, float|null>
     */
    private function segmentAverages(array $filters): array
    {
        $cards = $this->timeCards->forQuery($this->metrics->query($filters));

        $sums = [];
        $counts = [];

        foreach ($cards as $card) {
            foreach ($card->segments() as $key => $days) {
                if ($days === null) {
                    continue;
                }

                $sums[$key] = ($sums[$key] ?? 0.0) + $days;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $averages = [];

        foreach (array_keys(RequestTimeCard::SEGMENTS) as $key) {
            $averages[$key] = ($counts[$key] ?? 0) > 0
                ? round($sums[$key] / $counts[$key], 1)
                : null;
        }

        return $averages;
    }

    /**
     * Requests that have EVER been short of a document.
     *
     * Read from history rather than from the current status, for the reason
     * Stage 80's register 2 records: a rate that falls the moment a shortfall
     * is cleared measures nothing about the quality of submission, which is
     * what Art. 106 says this indicator is for.
     *
     * @param  array<string, mixed>  $filters
     */
    private function everIncompleteCount(array $filters): int
    {
        return $this->metrics->query($filters)
            ->whereExists(fn ($query) => $query
                ->from('request_status_history')
                ->join('request_statuses', 'request_statuses.id', '=', 'request_status_history.to_status_id')
                ->whereColumn('request_status_history.request_id', 'requests.id')
                ->whereIn('request_statuses.code', self::SHORTFALL_STATUSES))
            ->count();
    }

    /**
     * Deferral counts, from Stage 74's structured decision rather than prose.
     *
     * `deferral_required_document` is why the seventh indicator is answerable
     * at all: Art. 34 required that field, Stage 74 built it, and without it
     * "التأجيل بسبب نقص مستندات" would have to be guessed from free text.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function deferralCounts(array $filters): array
    {
        $deferrals = DB::table('decisions')
            ->join('meeting_requests', 'meeting_requests.id', '=', 'decisions.meeting_request_id')
            ->where('decisions.outcome', 'defer')
            ->whereIn('meeting_requests.request_id', $this->populationIds($filters))
            ->get(['meeting_requests.request_id', 'decisions.deferral_required_document']);

        return [
            'deferrals' => $deferrals->count(),
            'requests_deferred' => $deferrals->pluck('request_id')->unique()->count(),
            'for_documents' => $deferrals
                ->filter(fn (object $row) => trim((string) ($row->deferral_required_document ?? '')) !== '')
                ->count(),
        ];
    }

    /**
     * Agenda readiness and sitting load, over the sittings in the period.
     *
     * "قبل الاجتماع" is taken literally: the request must have reached `ready`
     * at a moment strictly before the sitting was scheduled. A file made ready
     * on the day it was heard was not ready *before* the meeting, which is the
     * rapporteur-efficiency question the indicator asks.
     *
     * Restricted to the filtered population, so a department filter answers
     * "how many of this department's files per sitting" rather than silently
     * ignoring the filter.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function agendaReadiness(array $filters): array
    {
        $items = DB::table('meeting_requests')
            ->join('meetings', 'meetings.id', '=', 'meeting_requests.meeting_id')
            ->where('meeting_requests.item_type', 'employee_request')
            ->whereIn('meeting_requests.request_id', $this->populationIds($filters))
            ->when($filters['date_from'] ?? null, fn ($query, $from) => $query->whereDate('meetings.scheduled_at', '>=', $from))
            ->when($filters['date_to'] ?? null, fn ($query, $to) => $query->whereDate('meetings.scheduled_at', '<=', $to))
            ->get(['meeting_requests.request_id', 'meetings.id as meeting_id', 'meetings.scheduled_at']);

        if ($items->isEmpty()) {
            return ['items' => 0, 'meetings' => 0, 'ready_before' => 0];
        }

        $readyAt = DB::table('request_status_history')
            ->join('request_statuses', 'request_statuses.id', '=', 'request_status_history.to_status_id')
            ->where('request_statuses.code', 'ready')
            ->whereIn('request_status_history.request_id', $items->pluck('request_id')->unique()->all())
            ->orderBy('request_status_history.changed_at')
            ->get(['request_status_history.request_id', 'request_status_history.changed_at'])
            ->groupBy('request_id')
            ->map(fn ($rows) => $rows->first()->changed_at);

        $readyBefore = $items->filter(function (object $item) use ($readyAt) {
            $ready = $readyAt[$item->request_id] ?? null;

            return $ready !== null
                && $item->scheduled_at !== null
                && strtotime((string) $ready) < strtotime((string) $item->scheduled_at);
        })->count();

        return [
            'items' => $items->count(),
            'meetings' => $items->pluck('meeting_id')->unique()->count(),
            'ready_before' => $readyBefore,
        ];
    }

    /**
     * Stage 77's returns over Stage 80's referrals.
     *
     * The denominator is referrals rather than decisions, deliberately: a
     * decision never sent for approval cannot be returned, so counting it
     * would dilute the indicator with files the approving body never saw —
     * and Art. 106 says this measures "جودة المحاضر والقرارات" as judged by
     * that body.
     *
     * @param  array<string, mixed>  $filters
     */
    private function approvalReturnRate(array $filters): ?float
    {
        $ids = $this->populationIds($filters);

        $referrals = DB::table('approval_referrals')
            ->whereIn('request_id', $ids)
            ->when($filters['date_from'] ?? null, fn ($query, $from) => $query->whereDate('referred_at', '>=', $from))
            ->when($filters['date_to'] ?? null, fn ($query, $to) => $query->whereDate('referred_at', '<=', $to))
            ->count();

        if ($referrals === 0) {
            return null;
        }

        $returned = DB::table('approval_returns')
            ->whereIn('request_id', $ids)
            ->when($filters['date_from'] ?? null, fn ($query, $from) => $query->whereDate('received_at', '>=', $from))
            ->when($filters['date_to'] ?? null, fn ($query, $to) => $query->whereDate('received_at', '<=', $to))
            ->count();

        return $this->rate($returned, $referrals);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private function populationIds(array $filters): array
    {
        /** @var Builder<Request> $query */
        $query = $this->metrics->query($filters);

        return $query->pluck('requests.id')->all();
    }

    /** Null rather than 0% when there is nothing to take a share of. */
    private function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}
