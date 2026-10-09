<?php

namespace App\Console\Commands;

use App\Models\Request;
use App\Services\NotificationDispatcher;
use App\Services\RequestDeadlineService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Marks open requests that have passed their type-derived SLA deadline.
 *
 * The command is idempotent: overdue_at is written once, which makes the
 * resulting flag stable for reporting and prevents a nightly sweep from
 * continually changing the breach timestamp.
 */
class FlagOverdueRequests extends Command
{
    protected $signature = 'requests:flag-overdue';

    protected $description = 'Flag open requests whose SLA due date has passed.';

    public function handle(RequestDeadlineService $deadlines, NotificationDispatcher $notifications): int
    {
        $backfilled = $this->backfillMissingDueDates($deadlines);
        $flagged = 0;

        Request::query()
            ->whereNull('overdue_at')
            ->whereNotNull('due_date')
            // A date SLA remains valid for the whole due date; it breaches
            // only from the following calendar day.
            ->whereDate('due_date', '<', today())
            ->whereDoesntHave('status', fn (Builder $query) => $query->whereIn(
                'code',
                ['cancelled', 'archived', 'not_approved', 'completed_closed'],
            ))
            ->orderBy('id')
            ->chunkById(100, function ($requests) use (&$flagged, $notifications) {
                foreach ($requests as $requestRecord) {
                    // The nullable predicate makes concurrent manual runs
                    // harmless: only the worker that reaches it first flags it.
                    $justFlagged = Request::query()
                        ->whereKey($requestRecord->id)
                        ->whereNull('overdue_at')
                        ->update(['overdue_at' => now()]);

                    $flagged += $justFlagged;

                    // Stage 23 — notify from inside that same guard, so a
                    // breach is announced exactly once no matter how often
                    // the sweep runs or how many workers race it.
                    if ($justFlagged > 0) {
                        $notifications->requestOverdue($requestRecord);
                    }
                }
            });

        $this->info("Backfilled {$backfilled} deadline(s); flagged {$flagged} overdue request(s).");

        return self::SUCCESS;
    }

    private function backfillMissingDueDates(RequestDeadlineService $deadlines): int
    {
        $updated = 0;

        Request::query()
            ->whereNull('due_date')
            ->whereNotNull('request_type_id')
            ->with('requestType:id,default_sla_days')
            ->orderBy('id')
            ->chunkById(100, function ($requests) use ($deadlines, &$updated) {
                foreach ($requests as $requestRecord) {
                    if ($requestRecord->requestType?->default_sla_days === null) {
                        continue;
                    }

                    $submittedAt = $requestRecord->submitted_at ?? $requestRecord->created_at;
                    if ($submittedAt === null) {
                        continue;
                    }

                    $dueDate = $deadlines->dueDateFor($requestRecord->requestType, $submittedAt);
                    $updated += Request::query()
                        ->whereKey($requestRecord->id)
                        ->whereNull('due_date')
                        ->update(['due_date' => $dueDate]);
                }
            });

        return $updated;
    }
}
