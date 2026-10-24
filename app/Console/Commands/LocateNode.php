<?php

namespace App\Console\Commands;

use App\Services\Maintenance\BinaryLocator;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Maintenance console — finds a Node.js that can build the SPA and points the
 * console's npm commands at it.
 *
 * A cPanel host usually carries several CloudLinux alt-nodejs builds, and the
 * locator's first match is the oldest (alt-nodejs10 sorts before 22), which on
 * some hosts cannot even start. So every node is actually run, and the newest
 * one that starts, has npm beside it and meets Vite's range wins. It is written
 * as `node`/`npm` launchers into BinaryLocator::localBinDirectory(), which the
 * locator searches first — the same trick as maintenance:install-composer, so
 * no .env change is needed (an explicit MAINTENANCE_NPM_PATH still wins).
 */
class LocateNode extends Command
{
    protected $signature = 'maintenance:locate-node';

    protected $description = 'Find a working Node.js that can build the frontend and point the console\'s npm commands at it.';

    public function handle(): int
    {
        if (! function_exists('proc_open')) {
            $this->error('proc_open is disabled on this host, so no Node.js can be run from here.');

            return self::FAILURE;
        }

        $probes = $this->probeAll();

        if ($probes === []) {
            $this->error('No Node.js was found on this host.');

            return self::FAILURE;
        }

        foreach ($probes as $probe) {
            $label = $probe['version'] ?? 'unknown version';
            $this->line("{$probe['dir']}: {$label} — ".(self::verdict($probe) ?? 'usable'));
        }

        $chosen = self::pick($probes);

        if ($chosen === null) {
            $this->error('None of these can build the frontend (Vite needs Node 20.19+ or 22.12+). Install one with cPanel → Setup Node.js App.');

            return self::FAILURE;
        }

        self::install($chosen['dir']);
        $this->info("Set Node.js {$chosen['version']} from {$chosen['dir']}.");

        return self::SUCCESS;
    }

    /**
     * Why a probe cannot be used, or null when it can.
     *
     * @param  array{dir: string, version: ?string, problem: ?string}  $probe
     */
    public static function verdict(array $probe): ?string
    {
        if ($probe['problem'] !== null) {
            return $probe['problem'];
        }

        // Vite 8's own engines range: ^20.19.0 || >=22.12.0.
        $version = (string) $probe['version'];
        $supported = version_compare($version, '22.12.0', '>=')
            || (str_starts_with($version, '20.') && version_compare($version, '20.19.0', '>='));

        return $supported ? null : 'outside Vite\'s range (needs 20.19+ or 22.12+)';
    }

    /**
     * The newest usable probe.
     *
     * @param  array<int, array{dir: string, version: ?string, problem: ?string}>  $probes
     * @return array{dir: string, version: ?string, problem: ?string}|null
     */
    public static function pick(array $probes): ?array
    {
        $usable = array_filter($probes, fn (array $probe) => self::verdict($probe) === null);
        usort($usable, fn (array $a, array $b) => version_compare($b['version'], $a['version']));

        return $usable[0] ?? null;
    }

    /** Writes the launchers. The npm one puts its own node first on PATH for npm's `#!/usr/bin/env node`. */
    public static function install(string $dir): void
    {
        $bin = BinaryLocator::localBinDirectory();

        if (! is_dir($bin)) {
            mkdir($bin, 0755, true);
        }

        // POSIX single quotes by hand: escapeshellarg() quotes for the PHP
        // host's shell, and on Windows that is cmd's double quotes.
        $sh = fn (string $value) => "'".str_replace("'", "'\\''", $value)."'";
        $launchers = [
            'node' => "#!/bin/sh\nexec {$sh($dir.'/node')} \"\$@\"\n",
            'npm' => "#!/bin/sh\nPATH={$sh($dir)}:\$PATH\nexport PATH\nexec {$sh($dir.'/npm')} \"\$@\"\n",
        ];

        foreach ($launchers as $name => $script) {
            file_put_contents($bin.DIRECTORY_SEPARATOR.$name, $script);
            @chmod($bin.DIRECTORY_SEPARATOR.$name, 0755);
        }
    }

    /** @return array<int, array{dir: string, version: ?string, problem: ?string}> */
    private function probeAll(): array
    {
        $bin = realpath(BinaryLocator::localBinDirectory()) ?: BinaryLocator::localBinDirectory();
        $probes = [];

        foreach (BinaryLocator::candidates('node') as $node) {
            // /usr/bin/node is often a symlink to one of the alt builds.
            $dir = dirname(realpath($node) ?: $node);

            if ($dir === $bin || isset($probes[$dir])) {
                continue;
            }

            $probes[$dir] = ['dir' => $dir, ...self::probe($dir)];
        }

        return array_values($probes);
    }

    /** @return array{version: ?string, problem: ?string} */
    private static function probe(string $dir): array
    {
        try {
            $process = new Process([$dir.'/node', '--version'], timeout: 15);
            $process->run();
        } catch (Throwable $e) {
            return ['version' => null, 'problem' => $e->getMessage()];
        }

        if (! $process->isSuccessful() || preg_match('/^v(\d+\.\d+\.\d+)/', trim($process->getOutput()), $m) !== 1) {
            $error = trim($process->getErrorOutput().' '.$process->getOutput());

            return ['version' => null, 'problem' => 'does not start: '.(strtok($error, "\n") ?: 'exit code '.$process->getExitCode())];
        }

        if (! @is_executable($dir.'/npm')) {
            return ['version' => $m[1], 'problem' => 'no npm beside it'];
        }

        return ['version' => $m[1], 'problem' => null];
    }
}
