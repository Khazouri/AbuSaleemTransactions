<?php

namespace App\Console\Commands;

use App\Models\Request;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stage 71 — walks [D] Appendix 38's delay ladder for every open request and
 * routes each rung to the party that appendix names.
 *
 * Deliberately NOT folded into `requests:flag-overdue`. That sweep enforces
 * Stage 17's HARD per-type deadline (`requests.due_date`, derived from
 * RequestType::default_sla_days) and produces a single one-way flag. This one
 * reads the SOFT per-stage target (Appendix 37, via
 * Request::stageTimeliness()), which is a different source, resets every time
 * the file moves, and has three rungs rather than one.
 *
 * Idempotent per rung, the same discipline `overdue_at` already has: a level
 * is announced only when it outranks whatever was last announced for the
 * request's CURRENT stage, so a file sitting red for a fortnight is announced
 * once, not fourteen times — while still escalating to حرج later if a legal
 * review subsequently records a statutory deadline.
 */
class EscalateDelayedRequests extends Command
{
    protected $signature = 'requests:escalate-delays';

    protected $description = 'Escalate delayed requests per Appendix 38 delay levels.';

    /**
     * Appendix 38's rungs in order. `green` is present because it is a real
     * level ("لا إجراء"); it simply never notifies and never gets recorded,
     * which is what lets a request drop back to green after a transition
     * without the ladder treating that as a step.
     */
    private const RANK = ['green' => 0, 'yellow' => 1, 'red' => 2, 'critical' => 3];

    public function handle(NotificationDispatcher $notifications): int
    {
        $escalated = 0;

        Request::query()
            // A concluded request is not "late" in any sense that anyone can
            // act on. Same list the overdue sweep excludes.
            ->whereDoesntHave('status', fn (Builder $query) => $query->whereIn(
                'code',
                ['cancelled', 'archived', 'not_approved', 'completed_closed'],
            ))
            ->with(['currentStage', 'latestStageLog', 'latestLegalReview'])
            ->orderBy('id')
            ->chunkById(100, function ($requests) use (&$escalated, $notifications) {
                foreach ($requests as $requestRecord) {
                    $escalated += $this->escalate($requestRecord, $notifications) ? 1 : 0;
                }
            });

        $this->info("Escalated {$escalated} delayed request(s).");

        return self::SUCCESS;
    }

    private function escalate(Request $requestRecord, NotificationDispatcher $notifications): bool
    {
        $timeliness = $requestRecord->stageTimeliness();

        // No sourced target for this stage, or the request has never been
        // logged at one — nothing to measure against, so nothing to escalate.
        if ($timeliness === null) {
            return false;
        }

        $level = $timeliness['level'];
        $already = $requestRecord->escalatedLevel() ?? 'green';

        if ((self::RANK[$level] ?? 0) <= (self::RANK[$already] ?? 0)) {
            return false;
        }

        // Record BEFORE notifying, and only if this worker won the write.
        // Optimistic guard: the update matches on the level this decision was
        // based on, so a manual run racing the scheduled one leaves exactly
        // one of them with a row to announce — the same shape as the overdue
        // sweep's `whereNull('overdue_at')` predicate, just against a value
        // that climbs rather than one that is set once.
        $priorLevel = $requestRecord->escalation_level;

        $claimed = Request::query()
            ->whereKey($requestRecord->id)
            ->when(
                $priorLevel === null,
                fn (Builder $query) => $query->whereNull('escalation_level'),
                fn (Builder $query) => $query->where('escalation_level', $priorLevel),
            )
            ->update(['escalation_level' => $level, 'escalation_notified_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        $notifications->delayEscalated($requestRecord, $level, $timeliness['elapsed_days']);

        return true;
    }
}
