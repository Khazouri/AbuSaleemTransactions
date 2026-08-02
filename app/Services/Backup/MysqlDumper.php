<?php

namespace App\Services\Backup;

use App\Contracts\DatabaseDumper;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/** Stage 26 — a restorable .sql dump produced by mysqldump. */
class MysqlDumper implements DatabaseDumper
{
    public function dump(string $absolutePath): void
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        $process = new Process(
            $this->command($config, $absolutePath),
            env: [
                // The password goes in the environment, never in argv: process
                // listings are readable by every user on the box, and a
                // credential on the command line is a credential leaked to all
                // of them. mysqldump reads MYSQL_PWD for exactly this reason.
                'MYSQL_PWD' => (string) ($config['password'] ?? ''),
            ],
            timeout: (float) config('backup.timeout'),
        );

        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            // A partially written file looks like a valid dump to anyone who
            // finds it later; drop it before reporting the failure.
            @unlink($absolutePath);

            throw new RuntimeException(
                'تعذّر إنشاء نسخة قاعدة البيانات: '.$this->tail($process->getErrorOutput()),
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    private function command(array $config, string $absolutePath): array
    {
        return [
            config('backup.mysqldump_path'),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? ''),
            // Consistent snapshot without locking the whole database, so a
            // scheduled backup doesn't block the application mid-run.
            '--single-transaction',
            '--quick',
            '--routines',
            // Without this mysqldump wants PROCESS privilege on MySQL 8 and
            // fails outright for an ordinary application user.
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--result-file='.$absolutePath,
            (string) ($config['database'] ?? ''),
        ];
    }

    /** mysqldump can be verbose on failure; the last lines carry the reason. */
    private function tail(string $output): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $output)));

        return implode(' ', array_slice($lines, -3)) ?: 'unknown error';
    }
}
