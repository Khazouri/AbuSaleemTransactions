<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates one signed-in identity per role, so the system can be exercised as
 * the people it was designed for rather than as the System Admin.
 *
 * WHY THIS EXISTS: AdminUserSeeder creates a single R08 account, and R08 holds
 * every action on every screen. Clicking through as that account proves almost
 * nothing — the six approval screens are single-role by design, and the happy
 * path deliberately needs four different people (R02 -> R05 -> R03 -> R05 ->
 * R06 -> R07) to reach `archived`. Without these accounts none of those gates
 * is ever actually hit. See TEST_PLAN.md for the script that uses them.
 *
 * SECURITY: every account below has the password `password`. This is
 * development data. It is NOT called from DatabaseSeeder — run it explicitly:
 *
 *     php artisan db:seed --class=TestUserSeeder
 *
 * Keeping it out of the default seed is the point: `migrate:fresh --seed` on a
 * real deployment must not quietly mint twelve known-password logins.
 *
 * Runs after RoleSeeder and DepartmentSeeder (both are looked up by `code`).
 */
class TestUserSeeder extends Seeder
{
    /**
     * [email, Arabic name, role codes, department code, phone, is_active]
     *
     * The shape here is chosen to make specific rules testable, not to be
     * tidy:
     *
     *  - THREE R04 members, because DecisionController::record() returns 422 on
     *    a tie and 422 on zero votes. With one member every vote is head vs.
     *    member and ties constantly, which makes the decision path — the thing
     *    Stage 21 exists for — effectively untestable.
     *  - A user holding BOTH R03 and R04, because User::screenPermissions()
     *    unions across roles. If it ever regressed to an intersection this is
     *    the only account that would notice.
     *  - An inactive user, because AuthController refuses them with its own
     *    message and WorkflowService::transition() refuses them again as an
     *    actor. Both need something to refuse.
     *  - R08 gets a SECOND admin rather than reusing admin@abusaleem.test, so a
     *    test pass can suspend or edit an admin without locking anyone out of
     *    the original account.
     *
     * @var list<array{0: string, 1: string, 2: list<string>, 3: string, 4: string, 5: bool}>
     */
    private const TEST_USERS = [
        ['r01.employee@abusaleem.test',   'موظف تجريبي',            ['R01'],        'ENG', '+218910000001', true],
        ['r02.reviewer@abusaleem.test',   'مقرر تجريبي',             ['R02'],        'REP', '+218910000002', true],
        ['r03.head@abusaleem.test',       'رئيس اللجنة التجريبي',    ['R03'],        'CMT', '+218910000003', true],
        ['r04.member1@abusaleem.test',    'عضو اللجنة الأول',        ['R04'],        'CMT', '+218910000004', true],
        ['r04.member2@abusaleem.test',    'عضو اللجنة الثاني',       ['R04'],        'CMT', '+218910000005', true],
        ['r04.member3@abusaleem.test',    'عضو اللجنة الثالث',       ['R04'],        'CMT', '+218910000006', true],
        ['r05.manager@abusaleem.test',    'مدير الشؤون الإدارية',    ['R05'],        'ADM', '+218910000007', true],
        ['r06.ministry@abusaleem.test',   'مندوب وزارة الحكم المحلي', ['R06'],        'ABS', '+218910000008', true],
        ['r07.director@abusaleem.test',   'المدير العام التجريبي',   ['R07'],        'ABS', '+218910000009', true],
        ['r08.sysadmin@abusaleem.test',   'مدير نظام تجريبي',        ['R08'],        'ADM', '+218910000010', true],
        ['multi.role@abusaleem.test',     'رئيس وعضو لجنة',          ['R03', 'R04'], 'CMT', '+218910000011', true],
        ['inactive.user@abusaleem.test',  'مستخدم موقوف',            ['R01'],        'FIN', '+218910000012', false],
    ];

    public function run(): void
    {
        // Both keyed by `code`, never by id: ids are only stable on a database
        // that was seeded from empty, and these seeders are all idempotent
        // upserts precisely so that assumption isn't needed.
        $departments = Department::query()->get()->keyBy('code');
        $roles = Role::query()->get()->keyBy('code');

        foreach (self::TEST_USERS as [$email, $name, $roleCodes, $departmentCode, $phone, $isActive]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'phone' => $phone,
                    'department_id' => $departments[$departmentCode]?->id,
                    'is_active' => $isActive,
                    // Pre-verified: there is no mail server in local dev.
                    'email_verified_at' => now(),
                ],
            );

            // sync(), not syncWithoutDetaching(): the role list above is the
            // definition of these accounts, so re-running must CORRECT a role
            // someone changed by hand mid-test rather than accumulate it.
            // AdminUserSeeder makes the opposite call for the opposite reason —
            // it grants R08 to a real account it does not otherwise own.
            $user->roles()->sync(
                collect($roleCodes)->map(fn (string $code) => $roles[$code]->id)->all(),
            );
        }
    }
}
