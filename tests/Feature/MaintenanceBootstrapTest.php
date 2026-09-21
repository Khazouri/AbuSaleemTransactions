<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Screen;
use App\Models\ScreenRolePermission;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one-time bootstrap that makes the maintenance console reachable.
 *
 * This is the only unauthenticated endpoint in the system that can change the
 * database, so what these tests pin is the fence around it: it answers only
 * with a long token supplied exactly, it closes itself once the console works,
 * and — the one with real consequences — it must not reset an existing
 * deployment's permission matrix on its way past.
 */
class MaintenanceBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a-sufficiently-long-bootstrap-token-000';

    private const URL = '/api/maintenance/bootstrap';

    public function test_it_does_not_answer_without_a_configured_token(): void
    {
        config()->set('maintenance.bootstrap_token', '');

        $this->get(self::URL.'?token=anything')->assertNotFound();
        $this->post(self::URL, ['token' => 'anything'])->assertNotFound();
    }

    /**
     * A short token on a public endpoint that can rebuild the database is the
     * actual risk, so a weak one disables the page rather than guarding it.
     */
    public function test_a_token_shorter_than_the_minimum_disables_the_page(): void
    {
        config()->set('maintenance.bootstrap_token', 'short-token');

        $this->get(self::URL.'?token=short-token')->assertNotFound();
    }

    public function test_a_wrong_token_is_indistinguishable_from_the_page_not_existing(): void
    {
        config()->set('maintenance.bootstrap_token', self::TOKEN);

        $this->get(self::URL.'?token=not-the-token')->assertNotFound();
        $this->get(self::URL)->assertNotFound();
    }

    public function test_the_confirmation_page_renders_and_changes_nothing_on_its_own(): void
    {
        config()->set('maintenance.bootstrap_token', self::TOKEN);

        $this->get(self::URL.'?token='.self::TOKEN)
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->assertSee('Maintenance bootstrap')
            // A GET must not be the thing that acts — the button is.
            ->assertSee('method="POST"', false);

        $this->assertSame(0, Screen::query()->where('code', 'maintenance')->count());
    }

    public function test_it_seeds_everything_on_a_database_that_has_never_been_seeded(): void
    {
        config()->set('maintenance.bootstrap_token', self::TOKEN);

        $this->assertSame(0, User::query()->count());

        $this->post(self::URL, ['token' => self::TOKEN])
            ->assertOk()
            ->assertSee('/maintenance');

        // A fresh install has nothing worth preserving, so it gets the lot —
        // including an administrator account to sign in with, without which the
        // console it just created would be unreachable anyway.
        $this->assertNotNull(User::query()->where('email', 'admin@abusaleem.test')->first());
        $this->assertTrue($this->consoleIsGrantedToAdmin());
    }

    /** Known-password test accounts only on a debug deployment, never otherwise. */
    public function test_it_seeds_the_test_users_only_when_app_debug_is_on(): void
    {
        config()->set('maintenance.bootstrap_token', self::TOKEN);

        config()->set('app.debug', false);
        $this->post(self::URL, ['token' => self::TOKEN])->assertOk();
        $this->assertNull(User::query()->where('email', 'r01.employee@abusaleem.test')->first());

        $this->revokeConsole();
        config()->set('app.debug', true);
        $this->post(self::URL, ['token' => self::TOKEN])->assertOk();
        $this->assertNotNull(User::query()->where('email', 'r01.employee@abusaleem.test')->first());
    }

    /**
     * The one with real consequences.
     *
     * Running the full DatabaseSeeder here would re-run
     * ScreenRolePermissionSeeder, whose job is to reset the matrix to its
     * documented defaults — silently discarding every grant an administrator
     * has since changed through the Roles & Permissions screen. Bootstrapping
     * one new screen must not cost someone their access configuration.
     */
    public function test_it_leaves_a_customised_permission_matrix_alone_on_an_existing_install(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Simulate an administrator having customised the matrix: R01 does not
        // hold `users.view` by default anywhere in the seeder.
        $usersScreen = Screen::query()->where('code', 'users')->firstOrFail();
        $employee = Role::query()->where('code', 'R01')->firstOrFail();

        ScreenRolePermission::query()
            ->where('screen_id', $usersScreen->id)
            ->where('role_id', $employee->id)
            ->update(['can_view' => true]);

        // And remove the console's own grant, so the bootstrap has work to do.
        $this->revokeConsole();

        config()->set('maintenance.bootstrap_token', self::TOKEN);

        $this->post(self::URL, ['token' => self::TOKEN])->assertOk();

        $this->assertTrue($this->consoleIsGrantedToAdmin(), 'The bootstrap should have granted the console.');

        $this->assertTrue(
            (bool) ScreenRolePermission::query()
                ->where('screen_id', $usersScreen->id)
                ->where('role_id', $employee->id)
                ->value('can_view'),
            'The bootstrap reset a customised permission the administrator had set.',
        );
    }

    /**
     * Self-disabling: no cleanup step for anyone to forget. Once the console is
     * reachable with an ordinary login, the back door is simply gone.
     */
    public function test_it_closes_itself_once_the_console_is_reachable(): void
    {
        $this->seed(DatabaseSeeder::class);
        config()->set('maintenance.bootstrap_token', self::TOKEN);

        $this->assertTrue($this->consoleIsGrantedToAdmin());

        $this->get(self::URL.'?token='.self::TOKEN)->assertNotFound();
        $this->post(self::URL, ['token' => self::TOKEN])->assertNotFound();
    }

    /** A half-finished bootstrap has to remain retryable. */
    public function test_it_stays_available_while_the_grant_is_still_missing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->revokeConsole();

        config()->set('maintenance.bootstrap_token', self::TOKEN);

        $this->get(self::URL.'?token='.self::TOKEN)->assertOk();
    }

    private function consoleIsGrantedToAdmin(): bool
    {
        $screenId = Screen::query()->where('code', 'maintenance')->value('id');
        $roleId = Role::query()->where('code', 'R08')->value('id');

        if ($screenId === null || $roleId === null) {
            return false;
        }

        return ScreenRolePermission::query()
            ->where('screen_id', $screenId)
            ->where('role_id', $roleId)
            ->where('can_view', true)
            ->exists();
    }

    private function revokeConsole(): void
    {
        ScreenRolePermission::query()
            ->whereIn('screen_id', Screen::query()->where('code', 'maintenance')->pluck('id'))
            ->update(['can_view' => false, 'can_add' => false, 'can_approve' => false]);
    }
}
