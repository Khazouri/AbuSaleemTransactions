<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use Illuminate\Database\Seeder;

/**
 * Entry point for `php artisan db:seed` and `migrate:fresh --seed`.
 *
 * ORDER IS NOT ARBITRARY. Each seeder looks up rows the previous ones created:
 *
 *   RoleSeeder          the 8 roles — nothing else works without them
 *   PermissionSeeder    attaches capabilities to those roles
 *   DepartmentSeeder    the org tree
 *   AdminUserSeeder     needs both the ADM department and the R08 role
 *   WorkflowStageSeeder the 11 stages (looks up responsible roles)
 *   RequestStatus.. the statuses a request can hold
 *   RequestType..   the request types
 *   WorkflowTransition. the Stage 14/16 normal and exception state machine
 *   AppealStatusSeeder  Stage 58's own small appeal status machine
 *   SettingSeeder       Stage 60's appeal_filing_deadline_days default (no
 *                       dependency — the settings table stands alone)
 *   ScreenSeeder        the 22 screens
 *   ScreenRolePerm..    the 22 x 8 matrix — needs screens AND roles
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Stage 22 — bootstrap data is not user activity. Without this, every
        // `migrate:fresh --seed` would open the audit viewer on hundreds of
        // rows describing the system setting itself up.
        AuditLog::withoutAuditing(fn () => $this->call([
            // Stage 2 — organisation and identity
            RoleSeeder::class,
            PermissionSeeder::class,
            DepartmentSeeder::class,
            AdminUserSeeder::class,

            // Stage 3 — workflow definition and lookup data
            WorkflowStageSeeder::class,
            RequestStatusSeeder::class,
            RequestTypeSeeder::class,
            // Stages 14/16 — data-driven normal and exception transition map.
            WorkflowTransitionSeeder::class,
            // Stage 58 — the appeal (تظلم) status machine, independent of the
            // above (see AppealStatusSeeder's own docblock).
            AppealStatusSeeder::class,
            SettingSeeder::class,
            ScreenSeeder::class,
            ScreenRolePermissionSeeder::class,
        ]));
    }
}
