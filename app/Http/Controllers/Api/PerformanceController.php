<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\ExportPeriodicReportRequest;
use App\Http\Requests\Performance\IndexPerformanceRequest;
use App\Services\Performance\EarlyWarningService;
use App\Services\Performance\PerformanceIndicatorService;
use App\Services\Performance\PeriodicReportService;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stage 81 — [D] Art. 106's indicators, Appendix 10's warnings and the three
 * periodic reports (Art. 107, Appendices 39 and 40).
 *
 * Rides the existing `reports` screen rather than introducing one of its own:
 * these describe the same population that screen already lists to everyone, so
 * a new screen would mean a new grant for a narrower view of data already
 * visible. Only the export is gated further, matching `reports,export` exactly.
 *
 * Every label is rendered server-side and travels beside its number, so the
 * screen and a forwarded file name an indicator identically — the same rule
 * Stage 80's registers follow.
 */
class PerformanceController extends Controller
{
    public function __construct(
        private readonly PerformanceIndicatorService $indicators,
        private readonly EarlyWarningService $warnings,
        private readonly PeriodicReportService $reports,
    ) {}

    /** Art. 106's twelve, plus Appendix 10's warning tally over the same period. */
    public function indicators(IndexPerformanceRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $locale = $request->performanceLocale();

        return response()->json([
            'data' => $this->indicators->indicators($filters, $locale),
            'warnings' => $this->warnings->summary($filters, $locale),
            'meta' => [
                'source' => 'Art. 106',
                'formats' => ReportExporter::FORMATS,
            ],
        ]);
    }

    /**
     * Appendix 10's alert list — one row per request carrying a warning.
     *
     * Capped rather than paginated: this is a triage list, and the appendix's
     * own point is that it should surface where work is stuck, not enumerate
     * every file. Ordered worst-first by the service.
     */
    public function warnings(IndexPerformanceRequest $request): JsonResponse
    {
        $locale = $request->performanceLocale();

        return response()->json([
            'data' => $this->warnings->alerts($request->filters(), $locale, 100),
            'summary' => $this->warnings->summary($request->filters(), $locale),
            'meta' => ['source' => 'Appendix 10'],
        ]);
    }

    /** One of the three periodic reports. */
    public function report(IndexPerformanceRequest $request, string $report): JsonResponse
    {
        abort_unless($this->reports->exists($report), 404);

        return response()->json([
            'data' => $this->reports->report($report, $request->filters(), $request->performanceLocale()),
            'meta' => ['formats' => ReportExporter::FORMATS],
        ]);
    }

    /** The same report as a file, reusing Stage 24's writers unchanged. */
    public function exportReport(ExportPeriodicReportRequest $request, string $report, ReportExporter $exporter): Response
    {
        abort_unless($this->reports->exists($report), 404);

        $locale = $request->performanceLocale();
        $filters = $request->filters();

        return $exporter->download(
            $this->reports->document($report, $filters, $locale, $this->metaLines($filters, $locale)),
            $request->exportFormat(),
        );
    }

    /**
     * Context lines printed under the title, so a forwarded report still says
     * what period it covers.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function metaLines(array $filters, string $locale): array
    {
        $applied = [];

        if ($from = $filters['date_from'] ?? null) {
            $applied[] = ($locale === 'ar' ? 'من تاريخ' : 'From').': '.$from;
        }
        if ($to = $filters['date_to'] ?? null) {
            $applied[] = ($locale === 'ar' ? 'إلى تاريخ' : 'To').': '.$to;
        }

        return [
            ($locale === 'ar' ? 'تاريخ الإصدار' : 'Generated').': '.now()->format('Y-m-d H:i'),
            $applied === []
                ? ($locale === 'ar' ? 'بدون تحديد فترة — كامل السجل' : 'No period — the whole record')
                : ($locale === 'ar' ? 'الفترة' : 'Period').' — '.implode(' | ', $applied),
            // Art. 107's own limit, stated on the document so a reader knows
            // the omission of names is deliberate rather than an oversight.
            $locale === 'ar'
                ? 'لا يتضمن هذا التقرير بيانات شخصية (المادة 107).'
                : 'This report contains no personal data (Art. 107).',
        ];
    }
}
