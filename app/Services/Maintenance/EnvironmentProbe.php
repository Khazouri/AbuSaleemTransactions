<?php

namespace App\Services\Maintenance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * What this host will and will not let the maintenance console do.
 *
 * The reason this class exists rather than the screen simply offering every
 * button: on cPanel shared hosting the interesting failure is not "the command
 * errored", it is "nothing happened and there is no way to find out why".
 * proc_open is in disable_functions, or max_execution_time is 30 seconds, or
 * composer is not at the path it was guessed to be. Each of those turns a
 * button into a silent dead end, so each is reported by name up front.
 *
 * Everything here is read-only and every probe is wrapped: a diagnostics screen
 * that itself 500s because one check threw is worse than no diagnostics at all.
 */
class EnvironmentProbe
{
    /** Functions whose absence changes what this console can do. */
    private const RELEVANT_FUNCTIONS = ['proc_open', 'exec', 'shell_exec', 'passthru', 'symlink', 'putenv'];

    /** @return array<string, mixed> */
    public function report(): array
    {
        return [
            'php' => $this->php(),
            'shell' => $this->shell(),
            'binaries' => $this->binaries(),
            'app' => $this->app(),
            'database' => $this->database(),
            'migrations' => $this->migrations(),
            'caches' => $this->caches(),
            'filesystem' => $this->filesystem(),
            'queue' => $this->queue(),
        ];
    }

    /** @return array<string, mixed> */
    private function php(): array
    {
        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));

        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            // The single most common reason a composer install "does nothing":
            // the worker is killed part-way and the browser sees a dead socket.
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'disabled_functions' => array_values(array_intersect(self::RELEVANT_FUNCTIONS, $disabled)),
            'extensions' => [
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'zip' => extension_loaded('zip'),
                'gd' => extension_loaded('gd'),
                'mbstring' => extension_loaded('mbstring'),
            ],
        ];
    }

    /**
     * Whether a subprocess can be started at all.
     *
     * Symfony Process needs proc_open specifically — exec/shell_exec being
     * available is not a substitute — so that is the function checked, and
     * function_exists() is what actually reflects disable_functions rather than
     * parsing the ini string a second time.
     *
     * @return array<string, mixed>
     */
    public function shell(): array
    {
        $configEnabled = (bool) config('maintenance.shell_enabled');
        $procOpen = function_exists('proc_open');

        return [
            'available' => $configEnabled && $procOpen,
            'config_enabled' => $configEnabled,
            'proc_open' => $procOpen,
            'reason' => match (true) {
                ! $procOpen => 'proc_open_disabled',
                ! $configEnabled => 'disabled_by_config',
                default => null,
            },
        ];
    }

    /**
     * Whether each configured binary actually answers.
     *
     * Probed rather than assumed because the paths are guesses until proven:
     * on cPanel npm lives under ~/nodevenv/... and composer under
     * /opt/cpanel/composer/bin. A wrong path here shows up as "not available"
     * on the screen instead of as a failed run with a confusing message.
     *
     * @return array<string, array<string, mixed>>
     */
    private function binaries(): array
    {
        $shell = $this->shell();
        $results = [];

        foreach ((array) config('maintenance.binaries') as $name => $path) {
            if (! $shell['available']) {
                $results[$name] = ['path' => $path, 'available' => false, 'version' => null];

                continue;
            }

            $results[$name] = ['path' => $path, ...$this->probeBinary((string) $path, $name)];
        }

        return $results;
    }

    /** @return array{available: bool, version: string|null} */
    private function probeBinary(string $path, string $name): array
    {
        // php answers -v; composer and npm answer --version. Asking the wrong
        // one produces a non-zero exit that would be read as "missing".
        $flag = $name === 'php' ? '-v' : '--version';

        try {
            $process = new Process([$path, $flag], timeout: 15);
            $process->run();

            if (! $process->isSuccessful()) {
                return ['available' => false, 'version' => null];
            }

            $first = strtok(trim($process->getOutput()), "\n");

            return ['available' => true, 'version' => $first === false ? null : $first];
        } catch (Throwable) {
            // A missing binary throws rather than exiting non-zero; both mean
            // the same thing to the reader.
            return ['available' => false, 'version' => null];
        }
    }

    /** @return array<string, mixed> */
    private function app(): array
    {
        return [
            'environment' => app()->environment(),
            // Debug left on in production leaks stack traces including database
            // credentials, so it is surfaced next to everything else rather
            // than left to be discovered.
            'debug' => (bool) config('app.debug'),
            'url' => config('app.url'),
            'timezone' => config('app.timezone'),
            'laravel_version' => app()->version(),
        ];
    }

    /** @return array<string, mixed> */
    private function database(): array
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        try {
            DB::connection()->getPdo();
            $connected = true;
            $error = null;
        } catch (Throwable $exception) {
            $connected = false;
            $error = $exception->getMessage();
        }

        return [
            'connection' => $connection,
            'driver' => $driver,
            'database' => config("database.connections.{$connection}.database"),
            'connected' => $connected,
            'error' => $error,
        ];
    }

    /**
     * How many migration files have not been applied.
     *
     * Computed directly from the migrator rather than by shelling out to
     * `migrate:status`, so the number is available even where no subprocess can
     * be started — which is precisely the host this screen is for.
     *
     * @return array<string, mixed>
     */
    private function migrations(): array
    {
        try {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles([database_path('migrations')]);

            // No repository yet means a database that has never been migrated:
            // everything is pending, which is the honest answer for a fresh
            // deployment rather than an error.
            if (! $migrator->repositoryExists()) {
                return ['total' => count($files), 'pending' => count($files), 'ran' => 0, 'error' => null];
            }

            $ran = $migrator->getRepository()->getRan();
            $pending = array_diff(array_keys($files), $ran);

            return [
                'total' => count($files),
                'ran' => count($ran),
                'pending' => count($pending),
                // Named, not just counted: knowing which migration is waiting is
                // what tells an administrator whether to expect a schema change.
                'pending_names' => array_values($pending),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return ['total' => null, 'ran' => null, 'pending' => null, 'error' => $exception->getMessage()];
        }
    }

    /**
     * Whether the production caches are warm.
     *
     * File presence, not a config flag: `config:cache` writes these files and
     * `optimize:clear` removes them, so their existence is the only truthful
     * answer — and a stale config cache silently ignoring an edited .env is the
     * classic "I changed it and nothing happened" on this kind of host.
     *
     * @return array<string, bool>
     */
    private function caches(): array
    {
        return [
            'config' => file_exists(base_path('bootstrap/cache/config.php')),
            'routes' => file_exists(base_path('bootstrap/cache/routes-v7.php'))
                || file_exists(base_path('bootstrap/cache/routes.php')),
            'events' => file_exists(base_path('bootstrap/cache/events.php')),
        ];
    }

    /** @return array<string, mixed> */
    private function filesystem(): array
    {
        $free = null;
        try {
            $bytes = @disk_free_space(base_path());
            $free = $bytes === false ? null : (int) $bytes;
        } catch (Throwable) {
            $free = null;
        }

        return [
            'storage_writable' => is_writable(storage_path()),
            'framework_writable' => is_writable(storage_path('framework')),
            'logs_writable' => is_writable(storage_path('logs')),
            'bootstrap_cache_writable' => is_writable(base_path('bootstrap/cache')),
            'storage_link' => File::exists(public_path('storage')),
            'free_bytes' => $free,
        ];
    }

    /**
     * Queue depth, but only where it is cheaply knowable.
     *
     * This application queues its notifications, so on a host with no worker
     * running they simply accumulate — a number here is what turns that from
     * "notifications are broken" into "the queue needs draining", which is a
     * button on this same screen.
     *
     * @return array<string, mixed>
     */
    private function queue(): array
    {
        $connection = config('queue.default');
        $pending = null;
        $failed = null;

        if ($connection === 'database') {
            try {
                $pending = DB::table('jobs')->count();
            } catch (Throwable) {
                $pending = null;
            }

            try {
                $failed = DB::table('failed_jobs')->count();
            } catch (Throwable) {
                $failed = null;
            }
        }

        return [
            'connection' => $connection,
            'pending' => $pending,
            'failed' => $failed,
        ];
    }
}
