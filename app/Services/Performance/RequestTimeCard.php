<?php

namespace App\Services\Performance;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Stage 81 — [D] Appendix 71's بطاقة قياس زمن المعاملة, one request's T1–T10.
 *
 * The appendix states its own purpose outright: "وبذلك تستطيع البلدية معرفة
 * أين يقع التأخير فعليًا بدل اعتبار اللجنة مسؤولة عن كامل مدة المعاملة" — the
 * point is to locate a delay, not to total one. So the card is ten independent
 * segments, and T10 is deliberately NOT their sum: a file can sit idle between
 * two measured segments, and pretending the parts add up to the whole would
 * hide exactly the gap the appendix wants found.
 *
 * **This class is also the primitive behind Art. 106's indicators.** Five of
 * that article's twelve are literally the mean of one segment here (T3, T4, T8,
 * T9, T10), so PerformanceIndicatorService averages these rather than deriving
 * the same durations a second way. One derivation, two consumers: the card a
 * user reads on one request and the municipality-wide average can never tell
 * two different stories about the same file.
 *
 * **A card never queries.** It is handed pre-loaded slices by TimeCardCompiler,
 * which reads the whole population in a fixed number of queries — the batching
 * Stage 80's own open item (4) asked whichever stage needed it to build.
 *
 * A segment whose two endpoints have not both happened is **null, never zero**.
 * A file that has not been executed has no execution duration, and averaging a
 * zero in would drag every column down with work that simply has not happened.
 */
class RequestTimeCard
{
    /**
     * Appendix 71's own ten, in its own order, each with the source's wording.
     *
     * @var array<string, array{ar: string, en: string}>
     */
    public const SEGMENTS = [
        't1' => ['ar' => 'من تقديم الموظف إلى إحالتها للجهة المعنية', 'en' => 'Submission to referral to the relevant body'],
        't2' => ['ar' => 'من الجهة المعنية إلى مقرر اللجنة', 'en' => 'Relevant body to the rapporteur'],
        't3' => ['ar' => 'مدة فحص الاكتمال', 'en' => 'Completeness check'],
        't4' => ['ar' => 'مدة استكمال النواقص', 'en' => 'Completing shortfalls'],
        't5' => ['ar' => 'مدة المراجعة القانونية', 'en' => 'Legal review'],
        't6' => ['ar' => 'من الجاهزية إلى الاجتماع', 'en' => 'Readiness to the sitting'],
        't7' => ['ar' => 'من الاجتماع إلى إعداد المحضر', 'en' => 'Sitting to the minutes'],
        't8' => ['ar' => 'مدة الاعتماد', 'en' => 'Approval'],
        't9' => ['ar' => 'مدة التنفيذ', 'en' => 'Execution'],
        't10' => ['ar' => 'المدة الكاملة من الطلب إلى الإقفال', 'en' => 'Whole cycle, submission to closure'],
    ];

    /**
     * Statuses that mean the file is blocked awaiting the employee's own
     * completion. Both, not one: Stage 16's `incomplete` is the intake-side
     * shortfall and Stage 29's `completion_required` is the committee-side
     * one, and Art. 19's loop is the same loop from either end.
     */
    private const SHORTFALL_STATUSES = ['incomplete', 'completion_required'];

    private const LEGAL_REVIEW_STATUS = 'under_legal_review';

    /** Art. 38's 15/16 — the file is out with an approving body. */
    private const AWAITING_APPROVAL_STATUSES = ['awaiting_municipal_approval', 'awaiting_central_approval', 'approved'];

    /**
     * @param  Collection<int, object>  $stageLogs  this request's own logs, oldest first
     * @param  Collection<int, object>  $statusHistory  this request's own history, oldest first
     * @param  array<string, CarbonInterface|null>  $marks  submitted_at / closed_at / executed_at
     * @param  array<string, CarbonInterface|null>  $meetingMarks  first sitting and its minutes
     * @param  array<string, CarbonInterface|null>  $referralMarks  Stage 80's referral pair
     */
    public function __construct(
        private readonly Collection $stageLogs,
        private readonly Collection $statusHistory,
        private readonly array $marks,
        private readonly array $meetingMarks = [],
        private readonly array $referralMarks = [],
    ) {}

    /**
     * The ten segments in days, each null when it cannot honestly be measured.
     *
     * @return array<string, float|null>
     */
    public function segments(): array
    {
        return [
            't1' => $this->days($this->marks['submitted_at'] ?? null, $this->arrivedAtStage('administrative_routing')),
            't2' => $this->days($this->arrivedAtStage('administrative_routing'), $this->arrivedAtStage('requirements_check')),
            't3' => $this->days($this->arrivedAtStage('requirements_check'), $this->leftStage('requirements_check')),
            // Summed rather than first-only: a file returned twice was blocked
            // twice, and Art. 19's استكمال loop is explicitly repeatable.
            't4' => $this->timeInStatuses(self::SHORTFALL_STATUSES),
            't5' => $this->timeInStatuses([self::LEGAL_REVIEW_STATUS]),
            't6' => $this->days($this->reachedStatus('ready'), $this->meetingMarks['held_at'] ?? null),
            't7' => $this->days($this->meetingMarks['held_at'] ?? null, $this->meetingMarks['minutes_generated_at'] ?? null),
            't8' => $this->approvalDuration(),
            't9' => $this->days($this->reachedStatus('final_approved'), $this->marks['executed_at'] ?? null),
            't10' => $this->days($this->marks['submitted_at'] ?? null, $this->marks['closed_at'] ?? null),
        ];
    }

    /**
     * The card as the API renders it: one row per segment, carrying [D]'s own
     * wording so the screen and any exported copy name the segment identically.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(string $locale = 'ar'): array
    {
        $segments = $this->segments();
        $rows = [];
        $index = 0;

        foreach (self::SEGMENTS as $key => $label) {
            $index++;
            $rows[] = [
                'key' => $key,
                // The appendix's own T-numbering, so a printed card can cite
                // "T8" and mean what Appendix 71 means by it.
                'number' => $index,
                'label' => $label[$locale] ?? $label['ar'],
                'days' => $segments[$key],
            ];
        }

        return $rows;
    }

    /**
     * Stage 80's referral is the honest source: it records the day the file
     * went out and the day the answer came back, which is what Art. 30 asks
     * the rapporteur to write down.
     *
     * The status fallback exists for files that predate that register — their
     * approval genuinely happened, and reporting null for all of them would
     * make the indicator describe only the newest files.
     */
    private function approvalDuration(): ?float
    {
        $referred = $this->referralMarks['referred_at'] ?? null;
        $received = $this->referralMarks['result_received_at'] ?? null;

        if ($referred !== null && $received !== null) {
            return $this->days($referred, $received);
        }

        return $this->days(
            $this->reachedAnyStatus(self::AWAITING_APPROVAL_STATUSES),
            $this->reachedStatus('final_approved'),
        );
    }

    /**
     * Total days spent inside a set of statuses, across every visit.
     *
     * A still-open interval is measured to now: a file that has been sitting
     * incomplete for a month is a month of delay whether or not anyone has
     * cleared it yet, which is precisely what Appendix 10's early warnings and
     * Art. 106's own "كشف نقاط التعطل" are looking for.
     *
     * @param  list<string>  $codes
     */
    private function timeInStatuses(array $codes): ?float
    {
        $total = null;
        $enteredAt = null;

        foreach ($this->statusHistory as $entry) {
            $to = $entry->to_status_code ?? null;
            $at = $entry->changed_at ?? null;

            if ($at === null) {
                continue;
            }

            if (in_array($to, $codes, true)) {
                // Re-entering a status already held is a no-op, not a second
                // clock: WorkflowService stamps a status row on every move
                // even when adjacent stages share one, so an unguarded start
                // would restart a running interval and lose its elapsed time.
                $enteredAt ??= $at;

                continue;
            }

            if ($enteredAt !== null) {
                $total = ($total ?? 0.0) + $this->diff($enteredAt, $at);
                $enteredAt = null;
            }
        }

        if ($enteredAt !== null) {
            $total = ($total ?? 0.0) + $this->diff($enteredAt, now());
        }

        return $total === null ? null : round($total, 1);
    }

    private function arrivedAtStage(string $code): ?CarbonInterface
    {
        return $this->stageLogs
            ->first(fn (object $log) => ($log->to_stage_code ?? null) === $code)
            ?->acted_at;
    }

    /**
     * The first departure *after* the first arrival — not simply the first log
     * naming the stage as its origin. A self-loop at that stage (Stage 21's
     * defer, Stage 78's suspend) reads as both, and taking the earlier row
     * would report a dwell the file never finished.
     */
    private function leftStage(string $code): ?CarbonInterface
    {
        $arrived = $this->arrivedAtStage($code);

        if ($arrived === null) {
            return null;
        }

        return $this->stageLogs
            ->first(fn (object $log) => ($log->from_stage_code ?? null) === $code
                && ($log->to_stage_code ?? null) !== $code
                && $log->acted_at !== null
                && $log->acted_at->greaterThanOrEqualTo($arrived))
            ?->acted_at;
    }

    private function reachedStatus(string $code): ?CarbonInterface
    {
        return $this->reachedAnyStatus([$code]);
    }

    /** @param  list<string>  $codes */
    private function reachedAnyStatus(array $codes): ?CarbonInterface
    {
        return $this->statusHistory
            ->first(fn (object $entry) => in_array($entry->to_status_code ?? null, $codes, true))
            ?->changed_at;
    }

    private function days(?CarbonInterface $from, ?CarbonInterface $to): ?float
    {
        if ($from === null || $to === null) {
            return null;
        }

        return round($this->diff($from, $to), 1);
    }

    /**
     * Clock skew or a hand-inserted row could invert a pair; a negative
     * duration is meaningless, so it floors at zero rather than subtracting
     * itself out of an average — the same call ReportMetricsService made.
     */
    private function diff(CarbonInterface $from, CarbonInterface $to): float
    {
        return $to->lessThan($from) ? 0.0 : $from->diffInDays($to, true);
    }
}
