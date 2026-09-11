<?php

namespace App\Services\Maintenance;

use App\Exceptions\MaintenanceCommandException;
use App\Models\MaintenanceRun;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs one allowlisted maintenance command and records what happened.
 *
 * Three properties are load-bearing and should survive any later edit:
 *
 * 1. The command comes from MaintenanceCommandCatalog by code. Nothing a caller
 *    sends is ever concatenated into a command line — argv is fixed in source.
 *
 * 2. One run at a time, held by a cache lock. Two concurrent `composer install`
 *    runs corrupt vendor/, and two concurrent `migrate` runs race on the
 *    migrations table. The second caller is refused outright rather than
 *    queued, because a maintenance action that silently starts several minutes
 *    later is worse than one that says no.
 *
 * 3. The run row is written BEFORE the command starts. On shared hosting the
 *    likeliest failure is PHP's own max_execution_time killing the worker
 *    mid-run, which leaves no opportunity to write anything afterwards — so
 *    without the up-front row, the one failure an administrator most needs to
 *    see would be the only one that leaves no trace.
 */
class MaintenanceRunner
{
    private const LOCK_KEY = 'maintenance:run';

    public function __construct(private readonly EnvironmentProbe $probe) {}

    /**
     * @throws MaintenanceCommandException when the command is unknown, the
     *                                     console is disabled, no subprocess can
     *                                     be started, or another run holds the lock.
     */
    public function run(string $code, ?User $actor = null): MaintenanceRun
    {
        if (! (bool) config('maintenance.enabled')) {
            throw MaintenanceCommandException::consoleDisabled();
        }

        $definition = MaintenanceCommandCatalog::find($code);

        if ($definition === null) {
            throw MaintenanceCommandException::unknownCommand($code);
        }

        if ($definition['kind'] === MaintenanceCommandCatalog::KIND_SHELL
            && ! $this->probe->shell()['available']) {
            throw MaintenanceCommandException::shellUnavailable();
        }

        $lock = Cache::lock(self::LOCK_KEY, (int) config('maintenance.lock_seconds'));

        if (! $lock->get()) {
            throw MaintenanceCommandException::alreadyRunning();
        }

        try {
            return $this->execute($code, $definition, $actor);
        } finally {
            // Guarded: `migrate:fresh` can drop the very table a database cache
            // store keeps its locks in, so releasing may itself throw. Failing
            // to release is harmless — the lock carries a TTL — whereas letting
            // that exception escape would replace a successful run's result
            // with a confusing error.
            try {
                $lock->release();
            } catch (Throwable) {
                // Intentionally ignored; see above.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function execute(string $code, array $definition, ?User $actor): MaintenanceRun
    {
        // Best effort only: many hosts ignore this, and some forbid it outright.
        // Worth attempting because where it does work it is the difference
        // between composer finishing and the worker being killed at 30 seconds.
        @set_time_limit((int) config('maintenance.timeout') + 30);

        $run = MaintenanceRun::create([
            'command' => $code,
            'kind' => $definition['kind'],
            'status' => MaintenanceRun::STATUS_RUNNING,
            'ran_by_user_id' => $actor?->id,
        ]);

        $startedAt = microtime(true);

        try {
            [$output, $exitCode] = $definition['kind'] === MaintenanceCommandCatalog::KIND_ARTISAN
                ? $this->runArtisan($definition)
                : $this->runShell($definition);

            $result = [
                'status' => $exitCode === 0 ? MaintenanceRun::STATUS_COMPLETED : MaintenanceRun::STATUS_FAILED,
                'exit_code' => $exitCode,
                'output' => $this->truncate($output),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            $result = [
                'status' => MaintenanceRun::STATUS_FAILED,
                'exit_code' => null,
                'output' => null,
                'error' => $this->truncate($exception->getMessage()),
            ];
        }

        $result['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        $result['finished_at'] = now();

        return $this->persist($run, $result);
    }

    /**
     * Writes the result back, re-creating the row if the command destroyed it.
     *
     * `migrate:fresh` drops every table including this one, so by the time it
     * returns the row written a moment ago no longer exists and an UPDATE would
     * quietly affect nothing — the most destructive command in the catalogue
     * would be the one that left no record of itself.
     *
     * @param  array<string, mixed>  $result
     */
    private function persist(MaintenanceRun $run, array $result): MaintenanceRun
    {
        try {
            if (MaintenanceRun::query()->whereKey($run->getKey())->exists()) {
                $run->update($result);

                return $run->refresh();
            }

            return MaintenanceRun::create([
                'command' => $run->command,
                'kind' => $run->kind,
                'ran_by_user_id' => $run->ran_by_user_id,
                ...$result,
            ]);
        } catch (Throwable) {
            // The command itself already ran; failing to file the paperwork
            // must not turn a completed run into an error response. The
            // in-memory model still carries the result for the caller.
            return $run->forceFill($result);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{0: string, 1: int}
     */
    private function runArtisan(array $definition): array
    {
        // In-process, which is the entire reason artisan commands work on a
        // host where nothing can be shelled out to.
        $exitCode = Artisan::call($definition['artisan'], $definition['params']);

        return [Artisan::output(), $exitCode];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{0: string, 1: int}
     */
    private function runShell(array $definition): array
    {
        $binaries = (array) config('maintenance.binaries');
        $path = $binaries[$definition['binary']] ?? null;

        if (! $path) {
            throw MaintenanceCommandException::binaryNotConfigured($definition['binary']);
        }

        $cwd = $definition['cwd'] === null ? base_path() : base_path($definition['cwd']);

        $process = new Process(
            array_merge([$path], $definition['args']),
            cwd: $cwd,
            env: $this->environment((string) $path),
            timeout: (float) config('maintenance.timeout'),
        );

        $process->run();

        // Both streams, because composer and npm write progress to stderr even
        // on success — showing only stdout would present a working install as
        // silence and a failure as an empty box.
        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        return [$output, $process->getExitCode() ?? 1];
    }

    /**
     * Environment for a subprocess: inherited, plus three fixes for the way
     * shared hosting runs PHP as a web user with no login shell.
     *
     * @return array<string, string>
     */
    private function environment(string $binaryPath): array
    {
        // A writable HOME. Without one composer refuses to start
        // ("COMPOSER_HOME could not be determined") and npm cannot place its
        // cache — both under a web user whose real home is often unwritable.
        $home = storage_path('app/maintenance-home');

        if (! is_dir($home)) {
            @mkdir($home, 0755, true);
        }

        $env = [
            'HOME' => $home,
            'COMPOSER_HOME' => $home.DIRECTORY_SEPARATOR.'composer',
            // Composer's own timeout is separate from Symfony's and defaults to
            // 300s per download; on a slow shared host that is the thing that
            // actually fires.
            'COMPOSER_PROCESS_TIMEOUT' => (string) config('maintenance.timeout'),
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'CI' => '1',
        ];

        // npm invokes node by name, so node must be findable. On cPanel both
        // live in the same nodevenv bin directory, which is not on the web
        // user's PATH — prepending the binary's own directory is what makes
        // `npm run build` resolve its interpreter.
        $directory = dirname($binaryPath);

        if ($directory !== '' && $directory !== '.' && is_dir($directory)) {
            $env['PATH'] = $directory.PATH_SEPARATOR.((string) getenv('PATH'));
        }

        return $env;
    }

    /**
     * Keeps the tail, not the head: a failure explains itself in its last lines,
     * and a truncated composer run that ends "installed 92 packages" is more
     * useful than one that ends mid-download.
     */
    private function truncate(?string $output): ?string
    {
        if ($output === null || $output === '') {
            return $output;
        }

        $limit = (int) config('maintenance.max_output_bytes');

        if (strlen($output) <= $limit) {
            return $output;
        }

        return "… [تم اختصار بداية المخرجات]\n".substr($output, -$limit);
    }
}
