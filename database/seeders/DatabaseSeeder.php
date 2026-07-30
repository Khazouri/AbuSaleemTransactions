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
 *   WorkflowTransition. the Stage 14 happy-path state machine
 *   ScreenSeeder        the 22 screens
 *   ScreenRolePerm..    the 22 x 8 matrix — needs screens AND roles
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
            // Stage 14 — data-driven happy-path transition map.
            WorkflowTransitionSeeder::class,
            ScreenSeeder::class,
            ScreenRolePermissionSeeder::class,
        ]);
    }
}
