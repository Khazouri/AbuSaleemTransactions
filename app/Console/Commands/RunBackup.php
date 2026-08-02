<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Stage 26 — take a snapshot and enforce the retention window.
 *
 * Scheduled nightly in routes/console.php, and runnable by hand before a risky
 * change. Pruning happens after the new snapshot lands, so a failed dump never
 * costs you the copies you already had.
 */
class RunBackup extends Command
{
    protected $signature = 'backup:run {--no-files : Dump the database only, skipping attachments and signatures}';

    protected $description = 'Create a database (and optionally file) backup, then prune expired snapshots.';

    public function handle(BackupService $backups): int
    {
        try {
            $backup = $backups->create(
                includeFiles: $this->option('no-files') ? false : null,
            );
        } catch (Throwable $exception) {
            // The failure is already recorded as a `failed` row by the service,
            // so the screen shows it even when nobody reads the scheduler log.
            $this->error('Backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $pruned = $backups->prune();

        $this->info(sprintf(
            'Created %s (%s KB); pruned %d expired snapshot(s).',
            $backup->filename,
            number_format(($backup->size_bytes ?? 0) / 1024, 1),
            $pruned,
        ));

        return self::SUCCESS;
    }
}
