<?php

namespace Tests\Feature;

use App\Contracts\DatabaseDumper;
use App\Models\Backup;
use App\Models\Role;
use App\Models\User;
use App\Services\Backup\BackupService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Stage 26 — backups.
 *
 * Every test here binds a fake DatabaseDumper. That is the whole reason the
 * contract exists: this suite runs on in-memory sqlite, where there is nothing
 * for mysqldump to dump and, on most machines, no mysqldump to call. Faking the
 * one step that needs a real server lets the archive, listing, download,
 * retention and permission behaviour all be tested for real.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_DUMP = "-- fake dump\nSELECT 1;\n";

    protected function setUp(): void
    {
        parent::setUp();
        // Both the archive destination and the attachment tree it copies live
        // on `local`, so one fake keeps the whole test off the real storage
        // directory — otherwise every run would leave zips in the repo.
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $this->fakeDumper();
    }

    public function test_creating_a_backup_writes_an_archive_containing_the_dump(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/backups', ['include_files' => false])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.includes_files', false)
            ->assertJsonPath('data.file_exists', true)
            ->assertJsonPath('data.created_by.name', $admin->name);

        $backup = Backup::findOrFail($response->json('data.id'));

        $this->assertTrue(Storage::disk($backup->disk)->exists($backup->path));
        $this->assertGreaterThan(0, $backup->size_bytes);
        $this->assertStringStartsWith('backup-', $backup->filename);

        // The archive has to actually be readable, and database.sql has to be
        // what the dumper produced — a zip that opens but is empty would pass
        // every check above.
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk($backup->disk)->path($backup->path)) === true);
        $this->assertSame(self::FAKE_DUMP, $zip->getFromName('database.sql'));
        $zip->close();
    }

    public function test_a_backup_can_include_the_private_attachment_files(): void
    {
        Storage::disk('local')->put('attachments/2026/contract.pdf', 'pdf-bytes');

        $backup = app(BackupService::class)->create($this->admin(), includeFiles: true);

        $zip = new ZipArchive;
        $zip->open(Storage::disk($backup->disk)->path($backup->path));
        $this->assertSame('pdf-bytes', $zip->getFromName('files/attachments/2026/contract.pdf'));
        $zip->close();

        $this->assertTrue($backup->includes_files);
    }

    public function test_the_archive_can_be_downloaded_and_deleted(): void
    {
        $admin = $this->admin();
        $backup = app(BackupService::class)->create($admin);

        $download = $this->actingAs($admin, 'sanctum')
            ->get("/api/backups/{$backup->id}/download")
            ->assertOk();

        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString($backup->filename, $download->headers->get('Content-Disposition'));

        $path = $backup->path;
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/backups/{$backup->id}")
            ->assertOk();

        // The row and the file go together; leaving either behind is a leak.
        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
        $this->assertFalse(Storage::disk($backup->disk)->exists($path));
    }

    public function test_downloading_a_backup_whose_file_is_gone_is_a_404_not_a_broken_stream(): void
    {
        $admin = $this->admin();
        $backup = app(BackupService::class)->create($admin);
        Storage::disk($backup->disk)->delete($backup->path);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/backups/{$backup->id}/download")
            ->assertNotFound();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/backups')
            ->assertOk()
            ->assertJsonPath('data.0.file_exists', false);
    }

    /**
     * A failed dump still has to be visible. Recording the row before
     * rethrowing is what puts the reason on the screen instead of only in the
     * scheduler's log.
     */
    public function test_a_failing_dump_records_the_failure_and_returns_422(): void
    {
        $this->app->bind(DatabaseDumper::class, fn () => new class implements DatabaseDumper
        {
            public function dump(string $absolutePath): void
            {
                throw new RuntimeException('mysqldump: command not found');
            }
        });

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/backups')
            ->assertStatus(422)
            ->assertJsonPath('message', 'mysqldump: command not found');

        $this->assertDatabaseHas('backups', [
            'status' => 'failed',
            'error' => 'mysqldump: command not found',
        ]);
    }

    public function test_pruning_removes_snapshots_past_the_retention_window(): void
    {
        config(['backup.retention_days' => 30]);
        $service = app(BackupService::class);

        $fresh = $service->create($this->admin());
        $stale = $service->create($this->admin());
        $stale->forceFill(['created_at' => now()->subDays(45)])->save();
        $stalePath = $stale->path;

        $this->assertSame(1, $service->prune());

        $this->assertDatabaseHas('backups', ['id' => $fresh->id]);
        $this->assertDatabaseMissing('backups', ['id' => $stale->id]);
        $this->assertFalse(Storage::disk($stale->disk)->exists($stalePath));
    }

    public function test_the_command_creates_a_snapshot_and_prunes(): void
    {
        $this->artisan('backup:run', ['--no-files' => true])
            ->assertExitCode(0);

        $backup = Backup::latest()->firstOrFail();
        $this->assertSame('completed', $backup->status);
        $this->assertFalse($backup->includes_files);
        // A scheduled run has no human behind it.
        $this->assertNull($backup->created_by_user_id);
    }

    /** `backup` seeds no grants at all, which means R08 and nobody else. */
    public function test_every_backup_endpoint_is_closed_to_non_admin_roles(): void
    {
        $backup = app(BackupService::class)->create($this->admin());
        $ministry = $this->userWithRole('R06');

        $this->actingAs($ministry, 'sanctum')->getJson('/api/backups')->assertForbidden();
        $this->actingAs($ministry, 'sanctum')->postJson('/api/backups')->assertForbidden();
        $this->actingAs($ministry, 'sanctum')->getJson("/api/backups/{$backup->id}/download")->assertForbidden();
        $this->actingAs($ministry, 'sanctum')->deleteJson("/api/backups/{$backup->id}")->assertForbidden();
    }

    // --- helpers ------------------------------------------------------------

    /** Stands in for mysqldump, writing a known payload we can assert on. */
    private function fakeDumper(): void
    {
        $this->app->bind(DatabaseDumper::class, fn () => new class implements DatabaseDumper
        {
            public function dump(string $absolutePath): void
            {
                file_put_contents($absolutePath, BackupTest::fakeDumpContents());
            }
        });
    }

    public static function fakeDumpContents(): string
    {
        return self::FAKE_DUMP;
    }

    private function admin(): User
    {
        return User::where('email', 'admin@abusaleem.test')->firstOrFail();
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
