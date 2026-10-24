<?php

namespace Tests\Feature;

use App\Console\Commands\LocateNode;
use App\Services\Maintenance\BinaryLocator;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The pick and the launchers are tested directly: probing runs each host's own
 * node binaries, which a test machine does not have at cPanel's paths.
 */
class LocateNodeTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/locate-node-'.uniqid();
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_the_newest_node_that_runs_and_suits_vite_is_picked(): void
    {
        $probes = [
            ['dir' => '/opt/alt/alt-nodejs10/root/usr/bin', 'version' => null, 'problem' => 'error while loading shared libraries: libicui18n.so.67'],
            ['dir' => '/opt/alt/alt-nodejs18/root/usr/bin', 'version' => '18.20.4', 'problem' => null],
            ['dir' => '/opt/alt/alt-nodejs20/root/usr/bin', 'version' => '20.19.1', 'problem' => null],
            ['dir' => '/opt/alt/alt-nodejs22/root/usr/bin', 'version' => '22.11.0', 'problem' => null],
            ['dir' => '/opt/alt/alt-nodejs24/root/usr/bin', 'version' => '24.1.0', 'problem' => 'no npm beside it'],
        ];

        $this->assertSame('/opt/alt/alt-nodejs20/root/usr/bin', LocateNode::pick($probes)['dir']);
        $this->assertNotNull(LocateNode::verdict($probes[0]));
        $this->assertNotNull(LocateNode::verdict($probes[1]));
        $this->assertNotNull(LocateNode::verdict($probes[3]), '22.0–22.11 is outside Vite 8\'s range');
        $this->assertNull(LocateNode::verdict(['dir' => 'x', 'version' => '22.12.0', 'problem' => null]));
        $this->assertNull(LocateNode::pick([$probes[0], $probes[1]]));
    }

    public function test_the_launchers_run_the_chosen_node_and_its_npm(): void
    {
        LocateNode::install('/opt/alt/alt-nodejs22/root/usr/bin');

        $dir = BinaryLocator::localBinDirectory();
        $this->assertStringContainsString("exec '/opt/alt/alt-nodejs22/root/usr/bin/node' \"\$@\"", file_get_contents($dir.'/node'));
        $this->assertStringContainsString("exec '/opt/alt/alt-nodejs22/root/usr/bin/npm' \"\$@\"", file_get_contents($dir.'/npm'));
    }
}
