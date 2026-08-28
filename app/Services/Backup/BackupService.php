<?php

namespace App\Services\Backup;

use App\Contracts\DatabaseDumper;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Stage 26 — takes a snapshot of the system and records that it happened.
 *
 * A snapshot is one .zip holding `database.sql` plus, by default, the private
 * attachment and signature trees. The two travel together deliberately: a
 * restored database whose requests point at files that no longer exist is
 * not a restored system.
 *
 * There is no restore counterpart here, on purpose. Reloading a database is a
 * server-side operation performed deliberately by an administrator, not
 * something that should sit one click away behind a web session.
 */
class BackupService
{
    public function __construct(private readonly DatabaseDumper $dumper) {}

    /**
     * Take a snapshot, recording the outcome either way.
     *
     * A failure writes a `failed` row *before* rethrowing, so a wrong
     * mysqldump path shows up on the backup screen as a red row with the
     * reason on it, rather than as a 500 the admin has to go and find in the
     * log.
     *
     * @throws RuntimeException
     */
    public function create(?User $actor = null, ?bool $includeFiles = null): Backup
    {
        $includeFiles ??= (bool) config('backup.include_files');
        $disk = config('backup.disk');
        $filename = 'backup-'.now()->format('Ymd-His').'.zip';
        $path = trim(config('backup.path'), '/').'/'.$filename;

        $workingDir = storage_path('app/backup-tmp');
        File::ensureDirectoryExists($workingDir);
        $sqlPath = $workingDir.DIRECTORY_SEPARATOR.'database.sql';
        $zipPath = $workingDir.DIRECTORY_SEPARATOR.$filename;

        try {
            $this->dumper->dump($sqlPath);
            $this->writeArchive($zipPath, $sqlPath, $includeFiles);

            $stream = fopen($zipPath, 'rb');
            Storage::disk($disk)->writeStream($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return Backup::create([
                'filename' => $filename,
                'disk' => $disk,
                'path' => $path,
                'size_bytes' => Storage::disk($disk)->size($path),
                'includes_files' => $includeFiles,
                'status' => Backup::STATUS_COMPLETED,
                'created_by_user_id' => $actor?->id,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Backup::create([
                'filename' => $filename,
                'disk' => $disk,
                'path' => $path,
                'includes_files' => $includeFiles,
                'status' => Backup::STATUS_FAILED,
                'error' => $exception->getMessage(),
                'created_by_user_id' => $actor?->id,
            ]);

            throw $exception;
        } finally {
            File::delete([$sqlPath, $zipPath]);
        }
    }

    /**
     * Drop snapshots past the retention window.
     *
     * Called after a new snapshot lands, never before: the oldest copy is only
     * worth dropping once a newer one exists.
     *
     * @return int How many were removed.
     */
    public function prune(): int
    {
        $days = (int) config('backup.retention_days');

        if ($days <= 0) {
            return 0;
        }

        $removed = 0;

        Backup::query()
            ->where('created_at', '<', now()->subDays($days))
            ->each(function (Backup $backup) use (&$removed) {
                $backup->deleteFile();
                $backup->delete();
                $removed++;
            });

        return $removed;
    }

    /** Builds the archive: always the dump, optionally the private file trees. */
    private function writeArchive(string $zipPath, string $sqlPath, bool $includeFiles): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('تعذّر إنشاء ملف النسخة الاحتياطية.');
        }

        $zip->addFile($sqlPath, 'database.sql');

        if ($includeFiles) {
            foreach ((array) config('backup.file_directories') as $directory) {
                $this->addDirectory($zip, $directory);
            }
        }

        $zip->close();
    }

    /**
     * Adds one private-disk directory to the archive under `files/<dir>/…`,
     * preserving the relative layout so a restore can drop it back in place.
     */
    private function addDirectory(ZipArchive $zip, string $directory): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->allFiles($directory) as $relativePath) {
            $zip->addFile($disk->path($relativePath), 'files/'.$relativePath);
        }
    }
}
