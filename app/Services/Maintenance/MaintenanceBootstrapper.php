<?php

namespace App\Services\Maintenance;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Screen;
use App\Models\ScreenRolePermission;
use App\Models\User;
use Database\Seeders\ScreenSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The one-time step that makes the maintenance console reachable.
 *
 * There is a genuine chicken-and-egg here: the console needs its own
 * `maintenance_runs` table and its `screens`/`screen_role_permissions` rows,
 * and creating those means running a migration and a seeder — which is exactly
 * what the console exists to do on a host with no shell. Something has to break
 * the loop, and this is it.
 *
 * It is deliberately NOT a general-purpose deployment endpoint. It does the
 * smallest set of things that ends with the console usable through the normal
 * authenticated screen, and then it refuses to run again — see isNeeded().
 */
class MaintenanceBootstrapper
{
    public const SCREEN = 'maintenance';

    public const ADMIN_ROLE = 'R08';

    /**
     * Whether the console is still unreachable, and therefore whether this
     * unauthenticated endpoint has any business existing.
     *
     * This is what makes the bootstrap SELF-DISABLING rather than something an
     * administrator has to remember to switch off: the moment the screen row
     * and its admin grant exist, the console can be reached the ordinary way
     * with a login, so the back door closes on its own. A half-finished run
     * (migration applied, seeding failed) still reports true, which is the
     * behaviour that lets it be retried.
     */
    public function isNeeded(): bool
    {
        try {
            if (! Schema::hasTable('screens') || ! Schema::hasTable('screen_role_permissions')) {
                return true;
            }

            $screenId = Screen::query()->where('code', self::SCREEN)->value('id');

            if ($screenId === null) {
                return true;
            }

            $roleId = Role::query()->where('code', self::ADMIN_ROLE)->value('id');

            if ($roleId === null) {
                return true;
            }

            return ! ScreenRolePermission::query()
                ->where('screen_id', $screenId)
                ->where('role_id', $roleId)
                ->where('can_view', true)
                ->exists();
        } catch (Throwable) {
            // An unreachable or unmigrated database is the strongest possible
            // "yes, this is needed".
            return true;
        }
    }

    /**
     * Brings the database up to date and grants the console to the admin role.
     *
     * @return array<int, array{step: string, ok: bool, detail: string}>
     */
    public function run(): array
    {
        $steps = [];

        $steps[] = $this->step('php artisan migrate --force', function () {
            Artisan::call('migrate', ['--force' => true]);

            return Artisan::output();
        });

        // A database with no users has never been seeded, so it needs the full
        // set — roles, departments, the admin account, the workflow map. There
        // is nothing there to preserve.
        if ($this->isFreshInstall()) {
            $steps[] = $this->step('php artisan db:seed --force (fresh install)', function () {
                AuditLog::withoutAuditing(fn () => Artisan::call('db:seed', ['--force' => true]));

                return Artisan::output();
            });

            return $steps;
        }

        /*
         * An EXISTING database is treated surgically, and this is the decision
         * not to undo: running the full DatabaseSeeder here would re-run
         * ScreenRolePermissionSeeder, whose whole job is to reset the matrix to
         * its documented defaults — silently discarding every permission an
         * administrator has since changed through the Roles & Permissions
         * screen. Bootstrapping a new screen must not cost someone their
         * access configuration.
         *
         * So: ScreenSeeder only (it upserts screen rows and touches no
         * permission at all), then one targeted grant below.
         */
        $steps[] = $this->step('Seed screen definitions (ScreenSeeder)', function () {
            AuditLog::withoutAuditing(fn () => (new ScreenSeeder)->setContainer(app())->run());

            return 'Screen rows upserted. Permission matrix left untouched.';
        });

        $steps[] = $this->step('Grant the maintenance screen to '.self::ADMIN_ROLE, fn () => $this->grantToAdmin());

        return $steps;
    }

    /** True when nothing has ever been seeded here. */
    private function isFreshInstall(): bool
    {
        try {
            return ! Schema::hasTable('users') || User::query()->count() === 0;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Opens every action on the maintenance screen for the admin role, and
     * nothing else.
     *
     * Written directly rather than through ScreenRolePermissionSeeder for the
     * reason above — that seeder rewrites all 33 x 11 rows, this rewrites one.
     */
    private function grantToAdmin(): string
    {
        $screen = Screen::query()->where('code', self::SCREEN)->first();
        $role = Role::query()->where('code', self::ADMIN_ROLE)->first();

        if ($screen === null || $role === null) {
            throw new \RuntimeException(
                'Could not find the maintenance screen or the '.self::ADMIN_ROLE.' role after seeding.',
            );
        }

        AuditLog::withoutAuditing(function () use ($screen, $role) {
            ScreenRolePermission::updateOrCreate(
                ['screen_id' => $screen->id, 'role_id' => $role->id],
                [
                    'can_view' => true,
                    'can_add' => true,
                    'can_edit' => true,
                    'can_delete' => true,
                    'can_approve' => true,
                    'can_print' => true,
                    'can_export' => true,
                ],
            );
        });

        return 'Granted to role '.self::ADMIN_ROLE.' ('.$role->name_ar.') on screen #'.$screen->id.'.';
    }

    /**
     * Runs one step, turning a failure into a recorded result rather than an
     * exception — a bootstrap that dies on step two should still show that step
     * one succeeded, because "how far did it get" is the only useful
     * information when there is no log to read.
     *
     * @param  callable(): string  $work
     * @return array{step: string, ok: bool, detail: string}
     */
    private function step(string $name, callable $work): array
    {
        try {
            return ['step' => $name, 'ok' => true, 'detail' => trim($work()) ?: 'Done.'];
        } catch (Throwable $exception) {
            return ['step' => $name, 'ok' => false, 'detail' => $exception->getMessage()];
        }
    }
}
