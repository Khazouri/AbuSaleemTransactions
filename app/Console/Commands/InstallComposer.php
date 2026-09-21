<?php

namespace App\Console\Commands;

use App\Services\Maintenance\BinaryLocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Maintenance console — installs a private composer for a host that has none.
 *
 * Downloads the latest stable composer.phar from getcomposer.org, verifies it
 * against the published SHA-256 (a tampered or truncated download is refused,
 * never written), and saves it as `composer` in
 * BinaryLocator::localBinDirectory(), which the locator searches — so the
 * console's composer commands find it with no .env change.
 *
 * Runs in-process (download + file writes only), so it works where proc_open
 * is disabled; *running* composer afterwards still needs proc_open.
 */
class InstallComposer extends Command
{
    protected $signature = 'maintenance:install-composer';

    protected $description = 'Download and verify composer.phar into the maintenance console\'s private bin directory.';

    private const PHAR_URL = 'https://getcomposer.org/download/latest-stable/composer.phar';

    public function handle(): int
    {
        try {
            $phar = Http::timeout(120)->get(self::PHAR_URL)->throw()->body();
            $expected = strtolower(trim(strtok(
                Http::timeout(30)->get(self::PHAR_URL.'.sha256')->throw()->body(), " \n"
            )));
        } catch (Throwable $e) {
            $this->error('Download from getcomposer.org failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! hash_equals($expected, hash('sha256', $phar))) {
            $this->error('Checksum mismatch — the download was not written.');

            return self::FAILURE;
        }

        $dir = BinaryLocator::localBinDirectory();

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true)) {
            $this->error("Cannot create {$dir}.");

            return self::FAILURE;
        }

        // Saved as `composer` itself: the phar opens with a php shebang, so
        // BinaryLocator::launcher() runs it through a verified CLI php rather
        // than a wrapper script that would bake in whichever php it guessed.
        $target = $dir.DIRECTORY_SEPARATOR.'composer';
        file_put_contents($target, $phar);
        @chmod($target, 0755);
        @unlink($dir.DIRECTORY_SEPARATOR.'composer.phar'); // an earlier install's copy

        $php = BinaryLocator::phpCli();
        $this->info("Installed composer to {$target} (runs with {$php}).");

        return self::SUCCESS;
    }
}
