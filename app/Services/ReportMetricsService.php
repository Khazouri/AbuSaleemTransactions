<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Stage 24 — every dashboard and report number is computed here.
 *
 * The dashboard tiles and the exported report read the same methods with the
 * same filter array, which is the point: a KPI card and the spreadsheet a
 * manager forwards upstairs must never disagree about how many transactions
 * are pending.
 */
class ReportMetricsService
{
    /**
     * Work is finished once it has been approved at the last checkpoint or
     * filed away. Everything else that isn't abandoned is still someone's job.
     */
    public const COMPLETED_STATUSES = ['final_approved', 'archived', 'completed_closed'];

    /** Abandoned outcomes: they leave the pipeline without ever completing. */
    public const ABANDONED_STATUSES = ['cancelled', 'rejected'];

    /**
     * Aggregates are recomputed at most once every five minutes per filter
     * combination. Dashboards get reloaded constantly and these are the only
     * queries in the app that scan the whole transactions table.
     */
    private const CACHE_TTL_SECONDS = 300;

    /**
     * Bumped by ReportCacheObserver whenever a transaction changes.
     *
     * A plain TTL would leave a freshly created transaction missing from the
     * dashboard for up to five minutes, which reads as a bug rather than as
     * caching. Folding a generation counter into the cache key means writes
     * invalidate everything instantly while repeated reads still stay cheap.
     */
    private const GENERATION_KEY = 'reports:generation';

    /**
     * Headline numbers for the dashboard tiles.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int|float|null>
     */
    public function kpis(array $filters): array
    {
        return $this->cached('kpis', $filters, function () use ($filters) {
            $total = $this->query($filters)->count();
            $completed = $this->query($filters)->whereHas(
                'status',
                fn (Builder $query) => $query->whereIn('code', self::COMPLETED_STATUSES),
            )->count();
            $abandoned = $this->query($filters)->whereHas(
                'status',
                fn (Builder $query) => $query->whereIn('code', self::ABANDONED_STATUSES),
            )->count();

            // Pending is the remainder rather than its own query: it keeps the
            // three buckets adding up to the total even for a transaction whose
            // status row was deleted and is therefore in none of the lists.
            $pending = $total - $completed - $abandoned;

            return [
                'total' => $total,
                'pending' => $pending,
                'completed' => $completed,
                'abandoned' => $abandoned,
                'completion_rate' => $total > 0 ? round($completed / $total * 100, 1) : 0.0,
                'overdue' => $this->overdueQuery($filters)->count(),
                'average_cycle_days' => $this->averageCycleDays($filters),
            ];
        });
    }

    /**
     * The supporting breakdowns rendered under the tiles.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function breakdowns(array $filters): array
    {
        return $this->cached('breakdowns', $filters, fn () => [
            'by_status' => $this->byStatus($filters),
            'by_stage' => $this->byStage($filters),
            'by_department' => $this->byDepartment($filters),
            'by_month' => $this->byMonth($filters),
        ]);
    }

    /**
     * Report rows — the same filtered population the KPIs describe, listed.
     *
     * Returned as a plain query so the caller decides between paginating it for
     * the screen and streaming all of it into an export.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    public function rowsQuery(array $filters): Builder
    {
        return $this->query($filters)
            ->with([
                'department:id,name_ar,name_en,code',
                'transactionType:id,code,name_ar,name_en',
                'status:id,code,name_ar,name_en,color',
                'currentStage:id,order_no,code,name_ar,name_en',
                'createdBy:id,name',
            ])
            ->latest('id');
    }

    /** Invalidate every cached aggregate. See GENERATION_KEY. */
    public function flush(): void
    {
        // increment() is a no-op against a key that doesn't exist yet on
        // several stores, so seed it first — add() only writes when absent.
        Cache::add(self::GENERATION_KEY, 0);
        Cache::increment(self::GENERATION_KEY);
    }

    /**
     * Apply the filter set shared by the dashboard, the report and the export.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    private function query(array $filters): Builder
    {
        // Every column is table-qualified: the breakdowns join this query onto
        // workflow_stages and departments, which carry their own created_at.
        return Transaction::query()
            ->when($filters['department_id'] ?? null, fn (Builder $query, int $id) => $query->where('transactions.department_id', $id))
            ->when($filters['type_id'] ?? null, fn (Builder $query, int $id) => $query->where('transactions.transaction_type_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $query, string $code) => $query->whereHas(
                'status',
                fn (Builder $statusQuery) => $statusQuery->where('code', $code),
            ))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $from) => $query->whereDate('transactions.created_at', '>=', $from))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $to) => $query->whereDate('transactions.created_at', '<=', $to));
    }

    /**
     * SLA breaches: flagged by the Stage 17 sweep AND still open.
     *
     * A transaction that blew its deadline but has since been completed or
     * cancelled is history, not an outstanding breach someone must act on.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    private function overdueQuery(array $filters): Builder
    {
        return $this->query($filters)
            ->whereNotNull('overdue_at')
            ->whereHas('status', fn (Builder $query) => $query->whereNotIn(
                'code',
                [...self::COMPLETED_STATUSES, ...self::ABANDONED_STATUSES],
            ));
    }

    /**
     * Mean days from submission to completion, over completed work only.
     *
     * Deliberately averaged in PHP: MySQL and the sqlite connection the test
     * suite runs on spell date arithmetic differently (DATEDIFF vs julianday),
     * and a KPI that changes value depending on the driver is worse than a
     * slightly less elegant query. The population is bounded by the filters and
     * only ever holds completed transactions, so it stays small.
     *
     * @param  array<string, mixed>  $filters
     */
    private function averageCycleDays(array $filters): ?float
    {
        $completedStatusIds = TransactionStatus::query()
            ->whereIn('code', self::COMPLETED_STATUSES)
            ->pluck('id');

        if ($completedStatusIds->isEmpty()) {
            return null;
        }

        // The first arrival at a completed status is the finish line — a later
        // archive of an already-approved transaction must not stretch the
        // measured cycle.
        $completions = DB::table('transaction_status_history')
            ->select('transaction_id', DB::raw('MIN(changed_at) as completed_at'))
            ->whereIn('to_status_id', $completedStatusIds)
            ->groupBy('transaction_id')
            ->pluck('completed_at', 'transaction_id');

        if ($completions->isEmpty()) {
            return null;
        }

        // submitted_at is the intended clock start; created_at covers rows that
        // predate Stage 13 stamping it. Coalesced here rather than in SQL so
        // Eloquent's datetime casts still apply.
        $starts = $this->query($filters)
            ->whereIn('transactions.id', $completions->keys())
            ->get(['transactions.id', 'transactions.submitted_at', 'transactions.created_at'])
            ->mapWithKeys(fn (Transaction $transaction) => [
                $transaction->id => $transaction->submitted_at ?? $transaction->created_at,
            ]);

        $durations = $starts
            ->map(function ($startedAt, $id) use ($completions) {
                if ($startedAt === null) {
                    return null;
                }

                $start = CarbonImmutable::parse($startedAt);
                $end = CarbonImmutable::parse($completions[$id]);

                // Clock skew or a hand-inserted row could invert these; a
                // negative cycle time is meaningless, so drop it rather than
                // let it drag the average below zero.
                return $end->lessThan($start) ? null : $start->diffInDays($end, true);
            })
            ->filter(fn (?float $days) => $days !== null);

        return $durations->isEmpty() ? null : round($durations->avg(), 1);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function byStatus(array $filters): array
    {
        $counts = $this->query($filters)
            ->select('transactions.status_id', DB::raw('COUNT(*) as total'))
            ->groupBy('transactions.status_id')
            ->pluck('total', 'status_id');

        // Driven by the status table rather than by DISTINCT over the results,
        // so a status with no transactions still shows as an explicit zero.
        return TransactionStatus::query()
            ->orderBy('id')
            ->get(['id', 'code', 'name_ar', 'name_en', 'color'])
            ->map(fn (TransactionStatus $status) => [
                'code' => $status->code,
                'name_ar' => $status->name_ar,
                'name_en' => $status->name_en,
                'color' => $status->color,
                'total' => (int) ($counts[$status->id] ?? 0),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function byStage(array $filters): array
    {
        return $this->query($filters)
            ->join('workflow_stages', 'workflow_stages.id', '=', 'transactions.current_stage_id')
            ->select([
                'workflow_stages.order_no',
                'workflow_stages.code',
                'workflow_stages.name_ar',
                'workflow_stages.name_en',
                DB::raw('COUNT(*) as total'),
            ])
            ->groupBy('workflow_stages.order_no', 'workflow_stages.code', 'workflow_stages.name_ar', 'workflow_stages.name_en')
            ->orderBy('workflow_stages.order_no')
            ->get()
            ->map(fn ($row) => [
                'order_no' => (int) $row->order_no,
                'code' => $row->code,
                'name_ar' => $row->name_ar,
                'name_en' => $row->name_en,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function byDepartment(array $filters): array
    {
        return $this->query($filters)
            ->join('departments', 'departments.id', '=', 'transactions.department_id')
            ->select([
                'departments.id',
                'departments.code',
                'departments.name_ar',
                'departments.name_en',
                DB::raw('COUNT(*) as total'),
            ])
            ->groupBy('departments.id', 'departments.code', 'departments.name_ar', 'departments.name_en')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'code' => $row->code,
                'name_ar' => $row->name_ar,
                'name_en' => $row->name_en,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Created vs completed over the last twelve months.
     *
     * Both series are bucketed in PHP for the same driver-portability reason as
     * averageCycleDays: DATE_FORMAT and strftime don't agree.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function byMonth(array $filters): array
    {
        $since = CarbonImmutable::now()->startOfMonth()->subMonths(11);

        $created = $this->query($filters)
            ->where('transactions.created_at', '>=', $since)
            ->pluck('created_at')
            ->countBy(fn ($date) => CarbonImmutable::parse($date)->format('Y-m'));

        $completedStatusIds = TransactionStatus::query()
            ->whereIn('code', self::COMPLETED_STATUSES)
            ->pluck('id');

        $completed = collect();
        if ($completedStatusIds->isNotEmpty()) {
            $completed = DB::table('transaction_status_history')
                ->whereIn('to_status_id', $completedStatusIds)
                ->where('changed_at', '>=', $since)
                // Restricted to the filtered population so the two series on
                // the chart always describe the same set of transactions.
                ->whereIn('transaction_id', $this->query($filters)->select('transactions.id'))
                ->pluck('changed_at')
                ->countBy(fn ($date) => CarbonImmutable::parse($date)->format('Y-m'));
        }

        return Collection::times(12, function (int $offset) use ($since, $created, $completed) {
            $month = $since->addMonths($offset - 1)->format('Y-m');

            return [
                'month' => $month,
                'created' => (int) ($created[$month] ?? 0),
                'completed' => (int) ($completed[$month] ?? 0),
            ];
        })->all();
    }

    /**
     * Memoise one computation per (bucket, filters, generation) triple.
     *
     * @template TValue
     *
     * @param  array<string, mixed>  $filters
     * @param  callable(): TValue  $compute
     * @return TValue
     */
    private function cached(string $bucket, array $filters, callable $compute): mixed
    {
        // Sorted so two callers that spelled the same filters in a different
        // order share one cache entry instead of computing it twice.
        ksort($filters);
        $generation = Cache::get(self::GENERATION_KEY, 0);
        $key = sprintf('reports:%s:%s:%s', $bucket, $generation, md5(json_encode($filters)));

        return Cache::remember($key, self::CACHE_TTL_SECONDS, $compute);
    }
}
