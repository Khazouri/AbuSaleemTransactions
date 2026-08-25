<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\NotificationDispatcher;
use App\Services\TransactionDeadlineService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Marks open transactions that have passed their type-derived SLA deadline.
 *
 * The command is idempotent: overdue_at is written once, which makes the
 * resulting flag stable for reporting and prevents a nightly sweep from
 * continually changing the breach timestamp.
 */
class FlagOverdueTransactions extends Command
{
    protected $signature = 'transactions:flag-overdue';

    protected $description = 'Flag open transactions whose SLA due date has passed.';

    public function handle(TransactionDeadlineService $deadlines, NotificationDispatcher $notifications): int
    {
        $backfilled = $this->backfillMissingDueDates($deadlines);
        $flagged = 0;

        Transaction::query()
            ->whereNull('overdue_at')
            ->whereNotNull('due_date')
            // A date SLA remains valid for the whole due date; it breaches
            // only from the following calendar day.
            ->whereDate('due_date', '<', today())
            ->whereDoesntHave('status', fn (Builder $query) => $query->whereIn(
                'code',
                ['cancelled', 'archived', 'completed_closed'],
            ))
            ->orderBy('id')
            ->chunkById(100, function ($transactions) use (&$flagged, $notifications) {
                foreach ($transactions as $transaction) {
                    // The nullable predicate makes concurrent manual runs
                    // harmless: only the worker that reaches it first flags it.
                    $justFlagged = Transaction::query()
                        ->whereKey($transaction->id)
                        ->whereNull('overdue_at')
                        ->update(['overdue_at' => now()]);

                    $flagged += $justFlagged;

                    // Stage 23 — notify from inside that same guard, so a
                    // breach is announced exactly once no matter how often
                    // the sweep runs or how many workers race it.
                    if ($justFlagged > 0) {
                        $notifications->transactionOverdue($transaction);
                    }
                }
            });

        $this->info("Backfilled {$backfilled} deadline(s); flagged {$flagged} overdue transaction(s).");

        return self::SUCCESS;
    }

    private function backfillMissingDueDates(TransactionDeadlineService $deadlines): int
    {
        $updated = 0;

        Transaction::query()
            ->whereNull('due_date')
            ->whereNotNull('transaction_type_id')
            ->with('transactionType:id,default_sla_days')
            ->orderBy('id')
            ->chunkById(100, function ($transactions) use ($deadlines, &$updated) {
                foreach ($transactions as $transaction) {
                    if ($transaction->transactionType?->default_sla_days === null) {
                        continue;
                    }

                    $submittedAt = $transaction->submitted_at ?? $transaction->created_at;
                    if ($submittedAt === null) {
                        continue;
                    }

                    $dueDate = $deadlines->dueDateFor($transaction->transactionType, $submittedAt);
                    $updated += Transaction::query()
                        ->whereKey($transaction->id)
                        ->whereNull('due_date')
                        ->update(['due_date' => $dueDate]);
                }
            });

        return $updated;
    }
}
