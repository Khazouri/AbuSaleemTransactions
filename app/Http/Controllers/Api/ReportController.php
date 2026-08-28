<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ExportReportRequest;
use App\Http\Requests\Report\IndexReportRequest;
use App\Http\Resources\RequestResource;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Services\ReportMetricsService;
use App\Services\Reports\ReportDocument;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stage 24 — the reports screen: a filtered request listing, the KPI
 * summary that describes it, and the same thing as a downloadable file.
 *
 * The listing and the export share ReportMetricsService, so a manager who
 * exports what is on screen gets exactly what was on screen. Only the export
 * is gated by `reports,export`; viewing is a separate, broader grant.
 */
class ReportController extends Controller
{
    /**
     * Column headings and KPI labels for the exported file.
     *
     * Kept here rather than in the SPA's locale files because the file is
     * rendered server-side — the browser never sees these strings.
     */
    private const LABELS = [
        'ar' => [
            'title' => 'تقرير الطلبات',
            'generated' => 'تاريخ الإصدار',
            'filters' => 'عوامل التصفية',
            'no_filters' => 'بدون تصفية — كل الطلبات',
            'department' => 'الإدارة',
            'type' => 'نوع الطلب',
            'status' => 'الحالة',
            'date_from' => 'من تاريخ',
            'date_to' => 'إلى تاريخ',
            'total' => 'إجمالي الطلبات',
            'pending' => 'قيد الإنجاز',
            'completed' => 'منجزة',
            'completion_rate' => 'نسبة الإنجاز',
            'overdue' => 'تجاوزت المهلة',
            'average_cycle_days' => 'متوسط زمن الدورة (يوم)',
            'columns' => [
                'الرقم المرجعي', 'الموضوع', 'الإدارة', 'النوع', 'الحالة',
                'المرحلة الحالية', 'الدرجة', 'تاريخ التقديم', 'تاريخ الاستحقاق',
                'تجاوزت المهلة', 'أنشأها', 'تاريخ الإنشاء',
            ],
            'yes' => 'نعم',
            'no' => 'لا',
            'none' => '—',
        ],
        'en' => [
            'title' => 'Requests Report',
            'generated' => 'Generated',
            'filters' => 'Filters',
            'no_filters' => 'No filters — all requests',
            'department' => 'Department',
            'type' => 'Type',
            'status' => 'Status',
            'date_from' => 'From',
            'date_to' => 'To',
            'total' => 'Total requests',
            'pending' => 'Pending',
            'completed' => 'Completed',
            'completion_rate' => 'Completion rate',
            'overdue' => 'SLA breaches',
            'average_cycle_days' => 'Avg. cycle time (days)',
            'columns' => [
                'Reference', 'Subject', 'Department', 'Type', 'Status',
                'Current stage', 'Grade', 'Submitted', 'Due date',
                'Overdue', 'Created by', 'Created at',
            ],
            'yes' => 'Yes',
            'no' => 'No',
            'none' => '—',
        ],
    ];

    public function __construct(private readonly ReportMetricsService $metrics) {}

    /** The filtered listing, plus the KPIs for the same population. */
    public function index(IndexReportRequest $request): AnonymousResourceCollection
    {
        $filters = $request->filters();

        $rows = $this->metrics
            ->rowsQuery($filters)
            ->paginate($request->validated('per_page') ?? 25)
            ->withQueryString();

        // additional() rather than a wrapper array so the payload keeps the
        // standard paginated shape the SPA's other list screens already read.
        return RequestResource::collection($rows)
            ->additional(['summary' => $this->metrics->kpis($filters)]);
    }

    /** Lookups travel separately so the filter bar works before any rows do. */
    public function filters(): JsonResponse
    {
        return response()->json([
            'data' => [
                'statuses' => RequestStatus::query()
                    ->orderBy('id')
                    ->get(['code', 'name_ar', 'name_en', 'color']),
                'departments' => Department::query()
                    ->orderBy('name_ar')
                    ->get(['id', 'name_ar', 'name_en', 'code']),
                'types' => RequestType::query()
                    ->orderBy('name_ar')
                    ->get(['id', 'code', 'name_ar', 'name_en']),
                'formats' => ReportExporter::FORMATS,
            ],
        ]);
    }

    /** Stage 24 — filtered request report as .xlsx or PDF. */
    public function export(ExportReportRequest $request, ReportExporter $exporter): Response
    {
        $filters = $request->filters();
        $locale = $request->exportLocale();
        $labels = self::LABELS[$locale];

        // Not paginated: an export that silently stopped at page one would be
        // worse than no export. The filters bound the size.
        $rows = $this->metrics->rowsQuery($filters)->get();

        $document = new ReportDocument(
            slug: 'requests-report',
            title: $labels['title'],
            columns: $labels['columns'],
            rows: $rows->map(fn (Request $requestRecord) => $this->exportRow($requestRecord, $locale, $labels))->all(),
            meta: $this->metaLines($filters, $labels, $locale),
            summary: $this->summaryPairs($filters, $labels),
            rtl: $locale === 'ar',
        );

        return $exporter->download($document, $request->exportFormat());
    }

    /**
     * @param  array<string, mixed>  $labels
     * @return array<int, string|int|null>
     */
    private function exportRow(Request $requestRecord, string $locale, array $labels): array
    {
        return [
            $requestRecord->reference_number ?? $labels['none'],
            $requestRecord->title,
            $this->localName($requestRecord->department, $locale, $labels),
            $this->localName($requestRecord->requestType, $locale, $labels),
            $this->localName($requestRecord->status, $locale, $labels),
            $this->localName($requestRecord->currentStage, $locale, $labels),
            $requestRecord->decision_grade,
            $requestRecord->submitted_at?->format('Y-m-d') ?? $labels['none'],
            $requestRecord->due_date?->format('Y-m-d') ?? $labels['none'],
            $requestRecord->isOverdue() ? $labels['yes'] : $labels['no'],
            $requestRecord->createdBy?->name ?? $labels['none'],
            $requestRecord->created_at?->format('Y-m-d H:i') ?? $labels['none'],
        ];
    }

    /**
     * Context lines printed under the title, so a forwarded file still says
     * what it is a report of.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $labels
     * @return array<int, string>
     */
    private function metaLines(array $filters, array $labels, string $locale): array
    {
        $applied = [];

        if ($id = $filters['department_id'] ?? null) {
            $applied[] = $labels['department'].': '.$this->localName(Department::find($id), $locale, $labels);
        }
        if ($id = $filters['type_id'] ?? null) {
            $applied[] = $labels['type'].': '.$this->localName(RequestType::find($id), $locale, $labels);
        }
        if ($code = $filters['status'] ?? null) {
            $status = RequestStatus::where('code', $code)->first();
            $applied[] = $labels['status'].': '.$this->localName($status, $locale, $labels);
        }
        if ($from = $filters['date_from'] ?? null) {
            $applied[] = $labels['date_from'].': '.$from;
        }
        if ($to = $filters['date_to'] ?? null) {
            $applied[] = $labels['date_to'].': '.$to;
        }

        return [
            $labels['generated'].': '.now()->format('Y-m-d H:i'),
            $applied === [] ? $labels['no_filters'] : $labels['filters'].' — '.implode(' | ', $applied),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $labels
     * @return array<int, array{label: string, value: string}>
     */
    private function summaryPairs(array $filters, array $labels): array
    {
        $kpis = $this->metrics->kpis($filters);

        return [
            ['label' => $labels['total'], 'value' => (string) $kpis['total']],
            ['label' => $labels['pending'], 'value' => (string) $kpis['pending']],
            ['label' => $labels['completed'], 'value' => (string) $kpis['completed']],
            ['label' => $labels['completion_rate'], 'value' => $kpis['completion_rate'].'%'],
            ['label' => $labels['overdue'], 'value' => (string) $kpis['overdue']],
            [
                'label' => $labels['average_cycle_days'],
                'value' => $kpis['average_cycle_days'] === null
                    ? $labels['none']
                    : (string) $kpis['average_cycle_days'],
            ],
        ];
    }

    /**
     * Every lookup in this schema carries name_ar/name_en, so one helper covers
     * departments, types, statuses and stages alike.
     *
     * @param  array<string, mixed>  $labels
     */
    private function localName(mixed $model, string $locale, array $labels): string
    {
        if ($model === null) {
            return $labels['none'];
        }

        $preferred = $locale === 'ar' ? $model->name_ar : $model->name_en;
        $fallback = $locale === 'ar' ? $model->name_en : $model->name_ar;

        return $preferred ?: ($fallback ?: $labels['none']);
    }
}
