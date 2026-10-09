<?php

namespace App\Services\Performance;

use App\Models\Request;
use App\Services\CommitteeStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stage 81 — [D] Appendix 11's لوحة متابعة أعمال اللجنة.
 *
 * **This replaces Stage 32's own six-bucket funnel rather than sitting beside
 * it.** Those buckets were that stage's invention; these ten are the sourced
 * version of the same question, and Track K exists to make the system identical
 * to [D]. Rendering both would show one request twice under two groupings,
 * which is worse than either.
 *
 * Two honesty points the appendix itself forces, and the payload states both
 * rather than letting the screen imply a partition the source never claims:
 *
 * - Bucket 1 is period-bounded — "المعاملات الجديدة — عدد الملفات التي وردت
 *   **خلال الفترة**" — while buckets 2–8 and 10 are "where things stand now".
 *   A new file is therefore also counted in whichever live bucket it sits in.
 * - Bucket 9 (متأخرة — "كل معاملة تجاوزت المدة التشغيلية المستهدفة") is Stage
 *   52/71's soft SLA, which **cross-cuts** every other bucket: a late file is
 *   still in whatever state it is in, so it is counted twice on purpose.
 *
 * Buckets 2–8 and 10 are disjoint by construction, because each names a
 * distinct set of current statuses and a request holds exactly one status.
 */
class CommitteeBoardService
{
    /**
     * Appendix 11's ten, in its own order, each mapped to the statuses this
     * system uses for the state the appendix names.
     *
     * The seventh splits in two, exactly as the appendix writes it
     * ("تفصل إلى: اعتماد البلدية · اعتماد مركزي").
     */
    private const BUCKETS = [
        'new' => [
            'ar' => 'المعاملات الجديدة', 'en' => 'New requests',
            'scope' => 'period',
        ],
        'under_review' => [
            'ar' => 'تحت المراجعة', 'en' => 'Under review',
            'scope' => 'live',
            // "الملفات لدى مقرر اللجنة أو العضو القانوني" — the rapporteur's own
            // examination plus Stage 68's Art. 21 legal review.
            'statuses' => ['in_review', 'registered', 'under_legal_review', 'under_discussion', 'awaiting_recommendation_approval'],
        ],
        'awaiting_completion' => [
            'ar' => 'بانتظار استكمال', 'en' => 'Awaiting completion',
            'scope' => 'live',
            'statuses' => ['incomplete', 'completion_required', 'returned'],
        ],
        'ready' => [
            'ar' => 'جاهزة للعرض', 'en' => 'Ready for presentation',
            'scope' => 'live',
            // "المكتملة وغير المدرجة بعد" — nominated is still not on an agenda.
            'statuses' => ['ready', 'nominated_for_committee'],
        ],
        'on_agenda' => [
            'ar' => 'مدرجة في الاجتماع القادم', 'en' => 'On the next agenda',
            'scope' => 'live',
            'statuses' => ['on_agenda', 'in_meeting'],
        ],
        'deferred' => [
            'ar' => 'مؤجلة', 'en' => 'Deferred',
            'scope' => 'live',
            'statuses' => ['deferred', 'legal_opinion_requested', 'referred_to_other_body'],
        ],
        'awaiting_municipal_approval' => [
            'ar' => 'بانتظار اعتماد البلدية', 'en' => 'Awaiting municipal approval',
            'scope' => 'live',
            'statuses' => ['awaiting_municipal_approval', 'approved', 'approved_with_conditions', 'decided', 'returned_by_approving_body'],
        ],
        'awaiting_central_approval' => [
            'ar' => 'بانتظار الاعتماد المركزي', 'en' => 'Awaiting central approval',
            'scope' => 'live',
            'statuses' => ['awaiting_central_approval'],
        ],
        'in_execution' => [
            'ar' => 'تحت التنفيذ', 'en' => 'In execution',
            'scope' => 'live',
            // "المعاملات المعتمدة التي لم تغلق" — approved through executed,
            // right up to but not including closure.
            'statuses' => ['final_approved', 'in_execution', 'executed', 'execution_suspended'],
        ],
        'overdue' => [
            'ar' => 'متأخرة', 'en' => 'Overdue',
            'scope' => 'cross_cutting',
        ],
        'closed' => [
            'ar' => 'مغلقة', 'en' => 'Closed',
            'scope' => 'live',
            'statuses' => ['completed_closed', 'archived'],
        ],
    ];

    public function __construct(private readonly CommitteeStatusService $committeeStatus) {}

    /**
     * The board.
     *
     * @param  array<string, mixed>  $filters  date_from/date_to bound bucket 1 only
     * @return list<array<string, mixed>>
     */
    public function board(array $filters = [], string $locale = 'ar'): array
    {
        $counts = $this->liveCounts();
        $rows = [];
        $number = 0;

        foreach (self::BUCKETS as $key => $bucket) {
            $number++;

            $total = match ($bucket['scope']) {
                'period' => $this->newInPeriod($filters),
                'cross_cutting' => $this->overdueCount(),
                default => collect($bucket['statuses'] ?? [])->sum(fn (string $code) => $counts[$code] ?? 0),
            };

            $rows[] = [
                'key' => $key,
                // Appendix 11's own numbering, with 7 and 8 being the two
                // halves the appendix explicitly splits its seventh into.
                'number' => $number,
                'label' => $bucket[$locale] ?? $bucket['ar'],
                'scope' => $bucket['scope'],
                'total' => $total,
            ];

            if ($key === 'deferred') {
                // "مؤجلة — **مع بيان مدة التأجيل**": the appendix asks for the
                // duration alongside the count, so the bucket carries it.
                $rows[count($rows) - 1]['average_days'] = $this->averageDeferralDays();
            }
        }

        return $rows;
    }

    /**
     * Current status counts across the whole population, in one query.
     *
     * @return array<string, int>
     */
    private function liveCounts(): array
    {
        return DB::table('requests')
            ->join('request_statuses', 'request_statuses.id', '=', 'requests.status_id')
            ->select('request_statuses.code', DB::raw('COUNT(*) as total'))
            ->groupBy('request_statuses.code')
            ->pluck('total', 'code')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /** @param  array<string, mixed>  $filters */
    private function newInPeriod(array $filters): int
    {
        $from = $filters['date_from'] ?? CarbonImmutable::now()->startOfMonth()->toDateString();
        $to = $filters['date_to'] ?? null;

        return Request::query()
            ->whereDate('requests.created_at', '>=', $from)
            ->when($to, fn ($query, $bound) => $query->whereDate('requests.created_at', '<=', $bound))
            ->count();
    }

    /**
     * Stage 17's flag AND still open — the same predicate the reports screen
     * uses, so a file cannot be late on one screen and not on another.
     */
    private function overdueCount(): int
    {
        return Request::query()
            ->whereNotNull('overdue_at')
            ->whereDoesntHave('status', fn ($query) => $query->whereIn('code', ['completed_closed', 'archived', 'cancelled', 'rejected', 'not_approved']))
            ->count();
    }

    /**
     * Mean days each currently-deferred file has been deferred.
     *
     * Measured from the status change that deferred it, not from the decision:
     * a file deferred, resumed and deferred again is waiting since the latest
     * deferral, and that is the duration the committee is being asked about.
     */
    private function averageDeferralDays(): ?float
    {
        $deferredIds = Request::query()
            ->whereHas('status', fn ($query) => $query->where('code', 'deferred'))
            ->pluck('id')
            ->all();

        if ($deferredIds === []) {
            return null;
        }

        $since = DB::table('request_status_history')
            ->join('request_statuses', 'request_statuses.id', '=', 'request_status_history.to_status_id')
            ->whereIn('request_status_history.request_id', $deferredIds)
            ->where('request_statuses.code', 'deferred')
            ->orderByDesc('request_status_history.changed_at')
            ->get(['request_status_history.request_id', 'request_status_history.changed_at'])
            ->groupBy('request_id')
            ->map(fn ($rows) => CarbonImmutable::parse($rows->first()->changed_at));

        if ($since->isEmpty()) {
            return null;
        }

        $now = CarbonImmutable::now();

        return round($since->map(fn (CarbonImmutable $at) => $at->diffInDays($now, true))->avg(), 1);
    }

    /**
     * Kept so the meetings dashboard can still say how many files are on the
     * rapporteur's own candidate worklist — a question Appendix 11 does not
     * ask but Stage 32's screen is built around.
     */
    public function candidatesCount(): int
    {
        return $this->committeeStatus->candidatesQuery()->count();
    }
}
