<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * 2026-10-25 — the workflow alone (statuses, stages, rules), for the
 * maintenance console's «إعادة زراعة مسار العمل»: a release that changes the
 * path must reach a live host without DatabaseSeeder, whose
 * ScreenRolePermissionSeeder puts every customised grant back to its default.
 * Statuses first: the rules look up the statuses they stamp.
 */
class WorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RequestStatusSeeder::class,
            WorkflowStageSeeder::class,
            WorkflowTransitionSeeder::class,
        ]);
    }
}
