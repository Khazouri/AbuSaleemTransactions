<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the initial System Admin account — the one you log in with to set
 * everything else up.
 *
 *   Email:    admin@abusaleem.test
 *   Password: password
 *
 * SECURITY: these are development credentials. Change the password (or drop
 * this seeder) before the system is used with real data.
 *
 * Runs last of the Stage 2 seeders because it needs both the ADM department
 * and the R08 role to already exist.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Park the admin in Administrative Affairs. Optional (`?->`) so the
        // seeder still works if the department seeder is skipped.
        $adminDept = Department::where('code', 'ADM')->first();

        // Keyed on email, so re-seeding updates the existing admin rather than
        // creating a second one.
        $admin = User::updateOrCreate(
            ['email' => 'admin@abusaleem.test'],
            [
                'name' => 'مدير النظام',
                'password' => Hash::make('password'),
                'department_id' => $adminDept?->id,
                'is_active' => true,
                // Pre-verified: there's no mail server in local dev.
                'email_verified_at' => now(),
            ],
        );

        // Grant R08 (System Admin) — full access to every screen.
        $r08 = Role::where('code', 'R08')->first();
        if ($r08) {
            // syncWithoutDetaching adds the role but leaves any other roles
            // the account may have picked up untouched.
            $admin->roles()->syncWithoutDetaching([$r08->id]);
        }
    }
}
