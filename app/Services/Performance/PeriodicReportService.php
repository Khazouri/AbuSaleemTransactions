<?php

namespace App\Services\Performance;

use App\Models\Request;
use App\Services\ReportMetricsService;
use App\Services\Reports\ReportDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stage 81 — [D] Art. 107's تقرير اللجنة الدوري, plus Appendix 39's monthly and
 * Appendix 40's annual reports.
 *
 * **Three documents over one period, not three services.** All three read the
 * same computations and are emitted through Stage 24's ReportDocument /
 * ReportExporter unchanged — the "a new document, not a new writer" split Stage
 * 25 established and Stage 80 reused for all twelve registers.
 *
 * **Art. 107's privacy rule is satisfied by construction, not by review.** The
 * article ends "ولا يتضمن التقرير العام بيانات شخصية لا تستلزمها أغراض
 * المتابعة", and every section here is a count, a share or an average. No
 * employee name, reference number or subject can reach any of the three,
 * because none of them is ever read.
 *
 * **Three items are deliberately absent and that is recorded rather than
 * fabricated**: Art. 107's توصيات تحسين الإجراءات, Appendix 39's أبرز الملاحظات
 * and Appendix 40's item 15 are the rapporteur's own writing, not a
 * computation, and Appendix 40's أكثر أسباب النقص has no structured source —
 * the only recorded shortfall reason is free text, and grouping free text would
 * report a guess as a statistic. Each is named in the document as a section the
 * rapporteur completes, so a reader can see what is missing rather than
 * assuming the report is whole.
 */
class PeriodicReportService
{
    /** The three shapes, by the source that defines each. */
    public const REPORTS = [
        'periodic' => ['ar' => 'تقرير اللجنة الدوري', 'en' => 'Committee periodic report', 'source' => 'Art. 107'],
        'monthly' => ['ar' => 'التقرير الشهري للجنة', 'en' => 'Committee monthly report', 'source' => 'Appendix 39'],
        'annual' => ['ar' => 'التقرير السنوي للجنة', 'en' => 'Committee annual report', 'source' => 'Appendix 40'],
    ];

    /**
     * Appendix 39's seven أسباب التعطيل, each mapped to the statuses that mean
     * a file is stuck for that reason.
     *
     * Genuinely derivable rather than attested: the appendix names seven
     * categories and this system already has a status for each of the states
     * they describe, so the report can say where work is actually stuck rather
     * than asking someone to estimate it.
     */
    private const BLOCKERS = [
        'awaiting_employee' => ['ar' => 'انتظار موظف', 'en' => 'Awaiting the employee', 'statuses' => ['incomplete', 'completion_required', 'returned']],
        'awaiting_administration' => ['ar' => 'انتظار إدارة', 'en' => 'Awaiting an administration', 'statuses' => ['routed_to_hr', 'routed_to_diwan', 'routed_to_committee_secretary', 'referred_to_other_body']],
        'legal_review' => ['ar' => 'مراجعة قانونية', 'en' => 'Legal review', 'statuses' => ['under_legal_review', 'legal_opinion_requested', 'execution_suspended']],
        'awaiting_meeting' => ['ar' => 'انتظار اجتماع', 'en' => 'Awaiting a sitting', 'statuses' => ['ready', 'nominated_for_committee', 'on_agenda', 'in_meeting', 'deferred']],
        'awaiting_approval' => ['ar' => 'انتظار اعتماد', 'en' => 'Awaiting approval', 'statuses' => ['awaiting_municipal_approval', 'approved', 'approved_with_conditions', 'decided', 'returned_by_approving_body']],
        'awaiting_ministry' => ['ar' => 'انتظار الوزارة', 'en' => 'Awaiting the ministry', 'statuses' => ['awaiting_central_approval']],
        'awaiting_execution' => ['ar' => 'انتظار تنفيذ', 'en' => 'Awaiting execution', 'statuses' => ['final_approved', 'in_execution', 'executed']],
    ];

    /** Sections the source asks for but no computation can supply. See the class docblock. */
    private const NARRATIVE_SECTIONS = [
        'periodic' => [
            ['ar' => 'أسباب التأخير المتكررة', 'en' => 'Recurring causes of delay'],
            ['ar' => 'توصيات تحسين الإجراءات', 'en' => 'Recommendations for improving procedures'],
        ],
        'monthly' => [
            ['ar' => 'أبرز الملاحظات', 'en' => 'Principal observations'],
        ],
        'annual' => [
            ['ar' => 'أكثر أسباب النقص', 'en' => 'Most frequent shortfall causes'],
            ['ar' => 'توصيات تطوير التشريعات أو الإجراءات', 'en' => 'Recommendations for developing legislation or procedures'],
        ],
    ];

    public function __construct(
        private readonly ReportMetricsService $metrics,
        private readonly PerformanceIndicatorService $indicators,
    ) {}

    /**
     * One report as sections of label/value lines.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function report(string $key, array $filters, string $locale = 'ar'): array
    {
        $definition = self::REPORTS[$key];

        return [
            'key' => $key,
            'title' => $definition[$locale] ?? $definition['ar'],
            'source' => $definition['source'],
            'sections' => match ($key) {
                'monthly' => $this->monthlySections($filters, $locale),
                'annual' => $this->annualSections($filters, $locale),
                default => $this->periodicSections($filters, $locale),
            },
            'narrative' => array_map(
                fn (array $section) => $section[$locale] ?? $section['ar'],
                self::NARRATIVE_SECTIONS[$key],
            ),
        ];
    }

    public function exists(string $key): bool
    {
        return isset(self::REPORTS[$key]);
    }

    /**
     * The same report as an exportable document.
     *
     * Rendered as label/value rows rather than as a table of requests: the
     * report is aggregate by definition, and a per-request table would be
     * precisely the personal data Art. 107 excludes.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $meta
     */
    public function document(string $key, array $filters, string $locale, array $meta): ReportDocument
    {
        $report = $this->report($key, $filters, $locale);
        $rows = [];

        foreach ($report['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $rows[] = [$section['title'], $item['label'], $this->cell($item['value'])];
            }
        }

        foreach ($report['narrative'] as $heading) {
            // Printed with an explicit placeholder rather than omitted: a
            // reader must be able to see that the section exists and is the
            // rapporteur's to complete, not silently absent.
            $rows[] = [
                $locale === 'ar' ? 'يستكملها المقرر' : 'Completed by the rapporteur',
                $heading,
                '—',
            ];
        }

        return new ReportDocument(
            slug: 'report-'.$key,
            title: $report['title'],
            columns: $locale === 'ar'
                ? ['القسم', 'البند', 'القيمة']
                : ['Section', 'Item', 'Value'],
            rows: $rows,
            meta: $meta,
            rtl: $locale === 'ar',
        );
    }

    /**
     * Art. 107's own fourteen elements, in the article's order.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function periodicSections(array $filters, string $locale): array
    {
        $movement = $this->movement($filters);
        $outcomes = $this->outcomes($filters);
        $kpis = $this->metrics->kpis($filters);

        return [
            $this->section($locale, 'حركة المعاملات', 'Request movement', [
                $this->item($locale, 'عدد المعاملات الواردة', 'Incoming requests', $movement['incoming']),
                $this->item($locale, 'المكتملة', 'Completed', $movement['completed']),
                $this->item($locale, 'الناقصة', 'Incomplete', $movement['incomplete']),
                $this->item($locale, 'المعاملات المغلقة', 'Closed requests', $movement['closed']),
            ]),
            $this->section($locale, 'نتائج العرض', 'Presentation outcomes', [
                $this->item($locale, 'الموضوعات المعروضة', 'Subjects presented', $outcomes['presented']),
                $this->item($locale, 'الموافق عليها', 'Approved', $outcomes['approved']),
                $this->item($locale, 'غير الموافق عليها', 'Not approved', $outcomes['not_approved']),
                $this->item($locale, 'المؤجلة', 'Deferred', $outcomes['deferred']),
                $this->item($locale, 'المحالة لوزارة الحكم المحلي', 'Referred to the ministry', $outcomes['referred_to_ministry']),
            ]),
            $this->section($locale, 'الاعتماد والتنفيذ', 'Approval and execution', [
                $this->item($locale, 'القرارات المعتمدة', 'Approved decisions', $outcomes['final_approved']),
                $this->item($locale, 'القرارات تحت التنفيذ', 'Decisions under execution', $outcomes['in_execution']),
            ]),
            $this->section($locale, 'متوسط مدد الإنجاز', 'Average completion times', [
                $this->item($locale, 'متوسط زمن الدورة (يوم)', 'Average cycle (days)', $kpis['average_cycle_days']),
            ]),
        ];
    }

    /**
     * Appendix 39's three named blocks.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function monthlySections(array $filters, string $locale): array
    {
        $movement = $this->movement($filters);
        $outcomes = $this->outcomes($filters);

        return [
            $this->section($locale, 'حركة المعاملات', 'Request movement', [
                $this->item($locale, 'رصيد أول المدة', 'Opening balance', $movement['opening_balance']),
                $this->item($locale, 'معاملات جديدة', 'New requests', $movement['incoming']),
                $this->item($locale, 'مكتملة', 'Completed', $movement['completed']),
                $this->item($locale, 'ناقصة', 'Incomplete', $movement['incomplete']),
                $this->item($locale, 'عرضت', 'Presented', $outcomes['presented']),
                $this->item($locale, 'مؤجلة', 'Deferred', $outcomes['deferred']),
                $this->item($locale, 'اعتمدت', 'Approved', $outcomes['final_approved']),
                $this->item($locale, 'نفذت', 'Executed', $movement['executed']),
                $this->item($locale, 'أغلقت', 'Closed', $movement['closed']),
                $this->item($locale, 'رصيد آخر المدة', 'Closing balance', $movement['closing_balance']),
            ]),
            $this->section($locale, 'أسباب التعطيل', 'Causes of delay', $this->blockerItems($locale)),
        ];
    }

    /**
     * Appendix 40's fifteen items, less the two it cannot supply.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function annualSections(array $filters, string $locale): array
    {
        $movement = $this->movement($filters);
        $outcomes = $this->outcomes($filters);
        $values = $this->indicators->values($filters);
        $population = $outcomes['presented'];

        return [
            $this->section($locale, 'الإجمالي والتوزيع', 'Totals and distribution', [
                $this->item($locale, 'إجمالي المعاملات', 'Total requests', $movement['incoming']),
            ]),
            $this->section($locale, 'التوزيع حسب النوع', 'Distribution by type', $this->typeItems($filters)),
            $this->section($locale, 'المعدلات', 'Rates', [
                $this->item($locale, 'معدلات الموافقة', 'Approval rate', $this->rate($outcomes['approved'], $population), '%'),
                $this->item($locale, 'معدلات عدم الموافقة', 'Non-approval rate', $this->rate($outcomes['not_approved'], $population), '%'),
                $this->item($locale, 'التأجيل', 'Deferral rate', $values['deferred_rate'], '%'),
                $this->item($locale, 'عدم الاختصاص', 'Outside jurisdiction', $outcomes['outside_jurisdiction']),
            ]),
            $this->section($locale, 'المدد', 'Durations', [
                $this->item($locale, 'متوسط زمن المعاملة', 'Average request time', $values['full_cycle_days']),
                $this->item($locale, 'متوسط زمن الاعتماد', 'Average approval time', $values['approval_days']),
            ]),
            $this->section($locale, 'أكثر أنواع الطلبات', 'Most frequent request types', $this->topTypeItems($filters)),
            $this->section($locale, 'أسباب التأخير', 'Causes of delay', $this->blockerItems($locale)),
            $this->section($locale, 'التظلمات والإعادات', 'Appeals and returns', [
                $this->item($locale, 'عدد التظلمات', 'Appeals', $this->appealCount($filters)),
                $this->item($locale, 'عدد الإعادات من جهة الاعتماد', 'Returns by the approving body', $this->returnCount($filters)),
            ]),
            $this->section($locale, 'مؤشرات الأداء', 'Performance indicators', array_map(
                fn (array $indicator) => [
                    'key' => $indicator['key'],
                    'label' => $indicator['label'],
                    'value' => $indicator['value'],
                    'unit' => $indicator['unit'],
                ],
                $this->indicators->indicators($filters, $locale),
            )),
        ];
    }

    /**
     * Movement counts. Opening/closing balance are Appendix 39's own framing:
     * how much open work the period started and ended with.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function movement(array $filters): array
    {
        $population = fn () => $this->metrics->query($filters);

        return [
            'incoming' => $population()->count(),
            'completed' => $population()->whereHas('status', fn ($q) => $q->whereIn('code', ReportMetricsService::COMPLETED_STATUSES))->count(),
            'incomplete' => $population()->whereHas('status', fn ($q) => $q->whereIn('code', ['incomplete', 'completion_required']))->count(),
            'closed' => $population()->whereNotNull('closed_at')->count(),
            'executed' => $population()->whereNotNull('executed_at')->count(),
            'opening_balance' => $this->openAt($filters['date_from'] ?? null),
            'closing_balance' => $this->openAt($filters['date_to'] ?? null, inclusive: true),
        ];
    }

    /**
     * Open work as at a date — created by then and not yet closed by then.
     *
     * A null bound means the balance is measured now, which is the honest
     * answer for a report run with no period.
     */
    private function openAt(?string $date, bool $inclusive = false): int
    {
        $query = Request::query();

        if ($date !== null) {
            $query->whereDate('requests.created_at', $inclusive ? '<=' : '<', $date)
                ->where(function ($inner) use ($date, $inclusive) {
                    $inner->whereNull('requests.closed_at')
                        ->orWhereDate('requests.closed_at', $inclusive ? '>' : '>=', $date);
                });
        } else {
            $query->whereNull('requests.closed_at');
        }

        return $query->count();
    }

    /**
     * Committee outcomes over the period, counted from decisions rather than
     * from current statuses: "الموافق عليها" is what the committee decided,
     * which stays true however the file has since moved.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function outcomes(array $filters): array
    {
        $ids = $this->metrics->query($filters)->pluck('requests.id')->all();

        if ($ids === []) {
            return array_fill_keys(
                ['presented', 'approved', 'not_approved', 'deferred', 'outside_jurisdiction', 'referred_to_ministry', 'final_approved', 'in_execution'],
                0,
            );
        }

        $decisions = DB::table('decisions')
            ->join('meeting_requests', 'meeting_requests.id', '=', 'decisions.meeting_request_id')
            ->whereIn('meeting_requests.request_id', $ids)
            ->select('decisions.outcome', DB::raw('COUNT(*) as total'))
            ->groupBy('decisions.outcome')
            ->pluck('total', 'outcome')
            ->map(fn ($total) => (int) $total);

        $statuses = DB::table('requests')
            ->join('request_statuses', 'request_statuses.id', '=', 'requests.status_id')
            ->whereIn('requests.id', $ids)
            ->select('request_statuses.code', DB::raw('COUNT(*) as total'))
            ->groupBy('request_statuses.code')
            ->pluck('total', 'code')
            ->map(fn ($total) => (int) $total);

        return [
            'presented' => (int) $decisions->sum(),
            'approved' => ($decisions['approve'] ?? 0) + ($decisions['conditional_approval'] ?? 0),
            'not_approved' => $decisions['reject'] ?? 0,
            'deferred' => $decisions['defer'] ?? 0,
            'outside_jurisdiction' => $decisions['no_jurisdiction'] ?? 0,
            'referred_to_ministry' => $statuses['awaiting_central_approval'] ?? 0,
            'final_approved' => $statuses['final_approved'] ?? 0,
            'in_execution' => ($statuses['in_execution'] ?? 0) + ($statuses['executed'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function blockerItems(string $locale): array
    {
        $counts = DB::table('requests')
            ->join('request_statuses', 'request_statuses.id', '=', 'requests.status_id')
            ->select('request_statuses.code', DB::raw('COUNT(*) as total'))
            ->groupBy('request_statuses.code')
            ->pluck('total', 'code')
            ->map(fn ($total) => (int) $total);

        $items = [];

        foreach (self::BLOCKERS as $key => $blocker) {
            $items[] = [
                'key' => $key,
                'label' => $blocker[$locale] ?? $blocker['ar'],
                'value' => collect($blocker['statuses'])->sum(fn (string $code) => $counts[$code] ?? 0),
                'unit' => 'count',
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function typeItems(array $filters): array
    {
        return $this->typeCounts($filters)->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function topTypeItems(array $filters): array
    {
        return $this->typeCounts($filters)->sortByDesc('value')->take(5)->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function typeCounts(array $filters)
    {
        return $this->metrics->query($filters)
            ->join('request_types', 'request_types.id', '=', 'requests.request_type_id')
            ->select(['request_types.code', 'request_types.name_ar', 'request_types.name_en', DB::raw('COUNT(*) as total')])
            ->groupBy('request_types.code', 'request_types.name_ar', 'request_types.name_en')
            ->get()
            ->map(fn ($row) => [
                'key' => $row->code,
                'label' => $row->name_ar ?: $row->name_en,
                'value' => (int) $row->total,
                'unit' => 'count',
            ]);
    }

    /** @param  array<string, mixed>  $filters */
    private function appealCount(array $filters): int
    {
        return DB::table('appeals')
            ->whereIn('original_request_id', $this->metrics->query($filters)->pluck('requests.id')->all())
            ->count();
    }

    /** @param  array<string, mixed>  $filters */
    private function returnCount(array $filters): int
    {
        return DB::table('approval_returns')
            ->whereIn('request_id', $this->metrics->query($filters)->pluck('requests.id')->all())
            ->count();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function section(string $locale, string $ar, string $en, array $items): array
    {
        return ['title' => $locale === 'ar' ? $ar : $en, 'items' => $items];
    }

    /** @return array<string, mixed> */
    private function item(string $locale, string $ar, string $en, float|int|null $value, string $unit = 'count'): array
    {
        return [
            'key' => str($en)->slug('_')->value(),
            'label' => $locale === 'ar' ? $ar : $en,
            'value' => $value,
            'unit' => $unit,
        ];
    }

    private function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    private function cell(float|int|null $value): string
    {
        return $value === null ? '—' : (string) $value;
    }
}
