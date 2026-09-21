<?php

namespace Tests\Feature;

use App\Services\Maintenance\BinaryLocator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstallComposerTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        // Real writes go to a throwaway storage path, never the repo's own.
        $this->storage = sys_get_temp_dir().'/install-composer-'.uniqid();
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function fakeDownload(string $body, string $checksum): void
    {
        Http::fake([
            '*/composer.phar.sha256' => Http::response($checksum.'  composer.phar'),
            '*/composer.phar' => Http::response($body),
        ]);
    }

    public function test_a_verified_download_installs_the_phar_and_a_wrapper(): void
    {
        $this->fakeDownload('PHAR', hash('sha256', 'PHAR'));

        $this->artisan('maintenance:install-composer')->assertSuccessful();

        $dir = BinaryLocator::localBinDirectory();
        $this->assertSame('PHAR', file_get_contents($dir.'/composer.phar'));
        $this->assertStringContainsString('composer.phar', file_get_contents($dir.'/composer'));
    }

    public function test_a_checksum_mismatch_writes_nothing(): void
    {
        $this->fakeDownload('TAMPERED', hash('sha256', 'PHAR'));

        $this->artisan('maintenance:install-composer')->assertFailed();

        $this->assertFileDoesNotExist(BinaryLocator::localBinDirectory().'/composer.phar');
    }
}
