<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * One System Admin user (R08), attached to the Administrative Affairs dept.
     */
    public function run(): void
    {
        $adminDept = Department::where('code', 'ADM')->first();

        $admin = User::updateOrCreate(
            ['email' => 'admin@abusaleem.test'],
            [
                'name' => 'مدير النظام',
                'password' => Hash::make('password'),
                'department_id' => $adminDept?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $r08 = Role::where('code', 'R08')->first();
        if ($r08) {
            $admin->roles()->syncWithoutDetaching([$r08->id]);
        }
    }
}
