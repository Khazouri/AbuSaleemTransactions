<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Order matters: roles must exist before permissions, stages and the
     * screen matrix; screens must exist before screen_role_permissions.
     *
     * Note: workflow_transitions is deliberately NOT seeded here — happy-path
     * rows arrive in Stage 14 and exception rows in Stage 16.
     */
    public function run(): void
    {
        $this->call([
            // Stage 2 — org + identity
            RoleSeeder::class,
            PermissionSeeder::class,
            DepartmentSeeder::class,
            AdminUserSeeder::class,

            // Stage 3 — workflow + lookup
            WorkflowStageSeeder::class,
            TransactionStatusSeeder::class,
            TransactionTypeSeeder::class,
            ScreenSeeder::class,
            ScreenRolePermissionSeeder::class,
        ]);
    }
}
