<?php

namespace App\Services\Maintenance;

/**
 * Finds composer/npm/node/php on a cPanel host and builds the environment they
 * need, shared by EnvironmentProbe (which reports availability) and
 * MaintenanceRunner (which runs them) so the two can never disagree.
 *
 * Why this exists: PHP runs as a web user with no login shell, so its PATH is
 * roughly /usr/bin:/bin and it has no HOME. `composer` works in the cPanel
 * terminal and "does not exist" from the web — composer refuses to start
 * without a HOME, and npm (a node script) cannot find `node`.
 */
class BinaryLocator
{
    /**
     * The configured path when it is explicit; for a bare name, the first
     * executable match on PATH or in the usual cPanel install locations, else
     * the bare name unchanged (so Process can still try its own lookup).
     */
    public static function resolve(string $configured): string
    {
        if ($configured === '' || str_contains($configured, '/') || str_contains($configured, '\\')) {
            return $configured;
        }

        foreach (self::searchDirectories() as $directory) {
            $candidate = $directory.DIRECTORY_SEPARATOR.$configured;

            // @: open_basedir on shared hosting warns rather than returning false.
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return $configured;
    }

    /**
     * Environment for a subprocess: a writable HOME (composer will not start
     * without one, npm needs it for its cache) and the binary's own directory
     * prepended to PATH (npm invokes `node` by name, and on cPanel both live in
     * the same nodevenv bin directory).
     *
     * @return array<string, string>
     */
    public static function environment(string $binaryPath): array
    {
        $home = storage_path('app/maintenance-home');

        if (! is_dir($home)) {
            @mkdir($home, 0755, true);
        }

        $env = [
            'HOME' => $home,
            'COMPOSER_HOME' => $home.DIRECTORY_SEPARATOR.'composer',
            // Composer's own timeout is separate from Symfony's and is what
            // actually fires on a slow shared host.
            'COMPOSER_PROCESS_TIMEOUT' => (string) config('maintenance.timeout'),
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'CI' => '1',
        ];

        $directory = dirname($binaryPath);

        if ($directory !== '' && $directory !== '.' && is_dir($directory)) {
            $env['PATH'] = $directory.PATH_SEPARATOR.((string) getenv('PATH'));
        }

        return $env;
    }

    /** Where `maintenance:install-composer` puts its private composer. */
    public static function localBinDirectory(): string
    {
        return storage_path('app/maintenance-home/bin');
    }

    /**
     * A php that can run a script. Under FPM, PHP_BINARY is php-fpm, which
     * cannot; cPanel's ea-php keeps the CLI at .../usr/bin/php beside
     * .../usr/sbin/php-fpm.
     */
    public static function phpCli(): string
    {
        $php = (string) config('maintenance.binaries.php');

        if (str_contains(basename($php), 'fpm')) {
            $sibling = dirname($php, 2).'/bin/php';

            return @is_executable($sibling) ? $sibling : self::resolve('php');
        }

        return self::resolve($php);
    }

    /** @return array<int, string> */
    private static function searchDirectories(): array
    {
        $directories = [self::localBinDirectory(), ...array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')))];
        $directories = [...$directories, '/usr/local/bin', '/usr/bin', '/opt/cpanel/composer/bin'];

        // The account home, from the app's own location (/home/<account>/...),
        // since the web user's HOME is often unset.
        if (preg_match('#^(/home\d*/[^/]+)#', base_path(), $m) === 1) {
            $home = $m[1];
            $directories[] = $home.'/bin';
            $directories[] = $home.'/.local/bin';
            $directories[] = $home.'/.composer/vendor/bin';
            // cPanel "Setup Node.js App": /home/<acct>/nodevenv/<app>/<version>/bin
            $directories = [...$directories, ...(@glob($home.'/nodevenv/*/*/bin') ?: [])];
        }

        // CloudLinux alt-nodejs builds.
        $directories = [...$directories, ...(@glob('/opt/alt/alt-nodejs*/root/usr/bin') ?: [])];

        return array_values(array_unique($directories));
    }
}
