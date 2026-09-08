<?php

namespace App\Observers;

use App\Services\ReportMetricsService;
use Illuminate\Database\Eloquent\Model;

/**
 * Stage 24 — keeps the cached dashboard aggregates honest.
 *
 * Originally bound to `Request` alone, on the reasoning that every KPI
 * ultimately depends on a requests row. **Stage 81 made that no longer true**:
 * Art. 106's indicators read decisions, sittings, agenda items and Stage 80's
 * approval referrals, and a referral is recorded deliberately WITHOUT moving
 * the request's stage or status — so a new one would leave نسبة القرارات
 * المعادة stale for the whole cache TTL with nothing to invalidate it.
 *
 * So the observer is model-agnostic and registered on each model whose writes
 * can move a number. `saved` rather than `created`/`updated` separately — the
 * reaction is the same either way, and one hook cannot drift out of step with
 * the other.
 */
class ReportCacheObserver
{
    public function __construct(private readonly ReportMetricsService $metrics) {}

    public function saved(Model $model): void
    {
        $this->metrics->flush();
    }

    public function deleted(Model $model): void
    {
        $this->metrics->flush();
    }
}
