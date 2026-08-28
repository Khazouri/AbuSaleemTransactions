<?php

namespace App\Observers;

use App\Models\Request;
use App\Services\ReportMetricsService;

/**
 * Stage 24 — keeps the cached dashboard aggregates honest.
 *
 * Every KPI ultimately depends on a `requests` row: status, stage,
 * due/overdue dates and department all live there, and the status history that
 * feeds cycle time is only ever written in the same database transaction as a
 * request save. So watching this one model is enough to catch every change
 * that could move a number on the dashboard.
 *
 * `saved` rather than `created`/`updated` separately — the reaction is the same
 * either way, and one hook can't drift out of step with the other.
 */
class ReportCacheObserver
{
    public function __construct(private readonly ReportMetricsService $metrics) {}

    public function saved(Request $requestRecord): void
    {
        $this->metrics->flush();
    }

    public function deleted(Request $requestRecord): void
    {
        $this->metrics->flush();
    }
}
