<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\IndexReportRequest;
use App\Services\ReportMetricsService;
use Illuminate\Http\JsonResponse;

/**
 * Stage 24 — the dashboard's live numbers.
 *
 * Read-only and deliberately thin: every figure comes from
 * ReportMetricsService, the same class the reports screen and its exports use,
 * so the tile a director looks at and the spreadsheet they were sent cannot
 * disagree. It accepts the report filter set too, which is what lets a
 * department head narrow the whole dashboard to their own work, and it counts
 * only the files a reader may open unless they hold the report export grant.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly ReportMetricsService $metrics) {}

    public function index(IndexReportRequest $request): JsonResponse
    {
        $filters = $request->filters();
        // The same carve-out ReportMetricsService::rowsQuery() makes: the
        // export holders are the oversight bodies and read the organisation
        // whole; everyone else sees the figures for their own files only.
        $scoped = ! $request->user()->hasScreenPermission('reports', 'can_export');
        if ($scoped) {
            $filters['visible_to'] = $request->user()->id;
        }

        return response()->json([
            'data' => [
                'scoped' => $scoped,
                'kpis' => $this->metrics->kpis($filters),
                'breakdowns' => $this->metrics->breakdowns($filters),
            ],
        ]);
    }
}
