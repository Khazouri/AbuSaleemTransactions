<?php

namespace Database\Seeders;

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
 *   TransactionStatus.. the statuses a transaction can hold
 *   TransactionType..   the request types
 *   ScreenSeeder        the 22 screens
 *   ScreenRolePerm..    the 22 x 8 matrix — needs screens AND roles
 *
 * Deliberately NOT seeded here: workflow_transitions. The state machine stays
 * empty until Stage 14 adds the happy path and Stage 16 the exception paths.
 * An empty table is the correct state right now, not an oversight.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Stage 2 — organisation and identity
            RoleSeeder::class,
            PermissionSeeder::class,
            DepartmentSeeder::class,
            AdminUserSeeder::class,

            // Stage 3 — workflow definition and lookup data
            WorkflowStageSeeder::class,
            TransactionStatusSeeder::class,
            TransactionTypeSeeder::class,
            ScreenSeeder::class,
            ScreenRolePermissionSeeder::class,
        ]);
    }
}
