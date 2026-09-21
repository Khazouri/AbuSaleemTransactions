<?php

namespace Tests\Feature;

use App\Models\MaintenanceRun;
use App\Models\Role;
use App\Models\Screen;
use App\Models\ScreenRolePermission;
use App\Models\User;
use App\Services\Maintenance\MaintenanceCommandCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The maintenance console.
 *
 * What these tests are really pinning is the boundary: only codes from
 * MaintenanceCommandCatalog can be run, nothing else reaches an executor, and
 * the two commands that destroy data need both a separate grant and a typed
 * confirmation. If a later change makes any of those pass, the screen has
 * turned into a remote shell.
 *
 * The commands actually executed here are artisan ones, deliberately — they
 * run in-process, so this suite exercises the real runner rather than a fake,
 * on the same in-memory sqlite connection the rest of the suite uses.
 */
class MaintenanceConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** Known-password logins are offered and accepted only while APP_DEBUG is on. */
    public function test_the_test_user_seeder_exists_only_when_app_debug_is_on(): void
    {
        config()->set('app.debug', false);
        $this->assertNotContains('db:seed:test-users', MaintenanceCommandCatalog::codes());
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/maintenance/run', ['command' => 'db:seed:test-users'])
            ->assertStatus(422);
        $this->assertNull(User::query()->where('email', 'r01.employee@abusaleem.test')->first());

        config()->set('app.debug', true);
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/maintenance/run', ['command' => 'db:seed:test-users'])
            ->assertOk();
        $this->assertNotNull(User::query()->where('email', 'r01.employee@abusaleem.test')->first());
    }

    public function test_the_screen_reports_the_hosts_own_limits_and_the_command_catalogue(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/maintenance')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.confirmation_phrase', MaintenanceCommandCatalog::CONFIRMATION_PHRASE)
            // R08 holds every action on this screen, `approve` included.
            ->assertJsonPath('data.can_run_destructive', true)
            ->assertJsonPath('data.environment.database.connected', true)
            ->assertJsonStructure([
                'data' => [
                    'environment' => [
                        'php' => ['version', 'max_execution_time', 'disabled_functions'],
                        'shell' => ['available', 'proc_open'],
                        'binaries',
                        'migrations' => ['pending'],
                        'caches' => ['config', 'routes'],
                        'filesystem' => ['storage_writable'],
                        'queue' => ['connection'],
                    ],
                    'commands' => [['code', 'kind', 'group', 'destructive', 'preview']],
                ],
            ])
            ->assertJsonCount(count(MaintenanceCommandCatalog::codes()), 'data.commands');
    }

    /**
     * The load-bearing one: anything not in the catalogue is refused by
     * validation, so it never reaches the runner at all.
     */
    public function test_a_command_outside_the_allowlist_is_refused_before_anything_runs(): void
    {
        foreach (['rm -rf /', 'php artisan migrate', 'tinker', 'migrate; whoami', ''] as $attempt) {
            $this->actingAs($this->admin(), 'sanctum')
                ->postJson('/api/maintenance/run', ['command' => $attempt])
                ->assertStatus(422)
                ->assertJsonValidationErrors('command');
        }

        $this->assertSame(0, MaintenanceRun::count());
    }

    public function test_a_safe_artisan_command_runs_and_records_a_completed_run(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/maintenance/run', ['command' => 'migrate:status'])
            ->assertOk()
            ->assertJsonPath('data.command', 'migrate:status')
            ->assertJsonPath('data.kind', 'artisan')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.exit_code', 0)
            ->assertJsonPath('data.ran_by.name', $admin->name);

        $run = MaintenanceRun::findOrFail($response->json('data.id'));

        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->duration_ms);
        // The point of capturing output at all: the run row has to carry what
        // the command actually said, not merely that it exited zero.
        $this->assertStringContainsString('Migration name', (string) $run->output);
    }

    /**
     * A command that blows up must come back as a recorded failure, not as a
     * 500 — on a host where this screen is the only console, an error page
     * with nothing written down is the worst possible outcome.
     *
     * The failure is induced by pointing a configured binary at a path that
     * does not exist — which is not a contrived case at all, it is the single
     * likeliest real misconfiguration on cPanel, where composer and npm live
     * at paths that have to be guessed. It also exercises the shell branch,
     * which no other test here reaches.
     *
     * Two candidates were rejected: `storage:link` still exits 0 when the link
     * already exists, and re-registering an artisan command as a throwing
     * closure does nothing here, because Artisan::command() defers to a
     * `starting` callback that has already fired by the time setUp() seeds.
     */
    public function test_a_failing_command_is_recorded_as_failed_rather_than_thrown_away(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('This machine cannot start a subprocess, so the shell branch is unreachable.');
        }

        config()->set('maintenance.binaries.php', '/definitely/not/a/real/binary/php');

        $this->actingAs($this->admin(), 'sanctum')
            // 200, not 500: the contract is that a blown-up command comes back
            // as a recorded failure. On a host where this screen is the only
            // console, an error page with nothing written down is the worst
            // possible outcome.
            ->postJson('/api/maintenance/run', ['command' => 'php:version'])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $run = MaintenanceRun::latest('id')->firstOrFail();

        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->finished_at);
        // Either channel is acceptable and which one fires is platform-specific
        // — a missing binary surfaces as a thrown exception on some systems and
        // as a non-zero exit with a message on stderr on others. What must hold
        // either way is that the row explains itself rather than recording a
        // bare failure with nothing to read.
        $this->assertNotEmpty($run->error ?? $run->output);
    }

    public function test_a_destructive_command_needs_the_confirmation_phrase(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/maintenance/run', ['command' => 'migrate:fresh'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmation');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/maintenance/run', ['command' => 'migrate:fresh', 'confirmation' => 'confirm'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmation');

        // Refused, so nothing ran and nothing was recorded.
        $this->assertSame(0, MaintenanceRun::count());
    }

    /**
     * The `approve` grant is what separates "may run maintenance" from "may
     * destroy the database", so a caller holding only `add` must be refused
     * even with the correct phrase.
     */
    public function test_a_destructive_command_needs_the_approve_grant_even_with_the_phrase(): void
    {
        $operator = $this->userWithRole('R08');
        $this->revokeAction($operator, 'can_approve');

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/maintenance/run', [
                'command' => 'migrate:fresh',
                'confirmation' => MaintenanceCommandCatalog::CONFIRMATION_PHRASE,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('command');

        $this->assertSame(0, MaintenanceRun::count());

        // ...while a non-destructive command on the same account still works,
        // proving the refusal is the destructive tier and not a broken account.
        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/maintenance/run', ['command' => 'migrate:status'])
            ->assertOk();
    }

    public function test_shell_commands_are_refused_with_a_reason_when_the_host_forbids_subprocesses(): void
    {
        config()->set('maintenance.shell_enabled', false);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/maintenance/run', ['command' => 'composer:install'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('command');

        $this->assertSame(0, MaintenanceRun::count());

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/maintenance')
            ->assertOk()
            ->assertJsonPath('data.environment.shell.available', false)
            ->assertJsonPath('data.environment.shell.reason', 'disabled_by_config');
    }

    public function test_the_kill_switch_stops_commands_while_leaving_diagnostics_readable(): void
    {
        config()->set('maintenance.enabled', false);

        // Read-only diagnostics still answer, so the screen can explain itself
        // rather than rendering a bare permission error.
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/maintenance')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonCount(0, 'data.commands');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/maintenance/run', ['command' => 'migrate:status'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('command');

        $this->assertSame(0, MaintenanceRun::count());
    }

    public function test_a_second_run_is_refused_while_another_holds_the_lock(): void
    {
        // Taken directly rather than by racing two requests: the guarantee
        // under test is that a held lock refuses, not how the holder got it.
        $this->assertTrue(Cache::lock('maintenance:run', 60)->get());

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/maintenance/run', ['command' => 'migrate:status'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('command');

        $this->assertSame(0, MaintenanceRun::count());
    }

    public function test_every_endpoint_is_closed_to_a_role_without_the_screen(): void
    {
        $employee = $this->userWithRole('R01');

        $this->actingAs($employee, 'sanctum')->getJson('/api/maintenance')->assertForbidden();
        $this->actingAs($employee, 'sanctum')->getJson('/api/maintenance/runs')->assertForbidden();
        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/maintenance/run', ['command' => 'migrate:status'])
            ->assertForbidden();
        $this->actingAs($employee, 'sanctum')->deleteJson('/api/maintenance/runs')->assertForbidden();

        $this->assertSame(0, MaintenanceRun::count());
    }

    public function test_the_history_lists_runs_and_can_be_cleared(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/maintenance/run', ['command' => 'migrate:status'])
            ->assertOk();

        $listed = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/maintenance/runs')
            ->assertOk()
            ->assertJsonPath('data.0.command', 'migrate:status')
            ->assertJsonPath('data.0.has_output', true);

        // The list carries a tail preview only; the detail endpoint is what
        // returns the whole captured output.
        $this->assertArrayNotHasKey('output', $listed->json('data.0'));

        $id = $listed->json('data.0.id');

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/maintenance/runs/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonStructure(['data' => ['output']]);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/maintenance/runs')
            ->assertOk();

        $this->assertSame(0, MaintenanceRun::count());
    }

    /**
     * Every artisan entry has to name a command that exists, or the button is a
     * 500 waiting to happen — a typo here would otherwise only surface the
     * first time someone clicked it on a production host.
     */
    public function test_every_artisan_command_in_the_catalogue_is_registered(): void
    {
        $registered = array_keys(Artisan::all());

        foreach (MaintenanceCommandCatalog::COMMANDS as $code => $definition) {
            if ($definition['kind'] !== MaintenanceCommandCatalog::KIND_ARTISAN) {
                continue;
            }

            $this->assertContains(
                $definition['artisan'],
                $registered,
                "Catalogue entry [{$code}] points at an artisan command that does not exist.",
            );
        }
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

    /** Turns one action off for every role the user holds, on this screen only. */
    private function revokeAction(User $user, string $column): void
    {
        ScreenRolePermission::query()
            ->whereIn('role_id', $user->roles()->pluck('roles.id'))
            ->whereIn('screen_id', Screen::query()->where('code', 'maintenance')->pluck('id'))
            ->update([$column => false]);
    }
}
