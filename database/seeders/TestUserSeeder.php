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
 * path deliberately needs several different people to reach `in_execution`:
 * submit (R01) -> direct-manager review + administrative routing (the
 * submitter's own manager, or an R08 override) -> receive & register
 * (R05/R10/R09, whichever matches the chosen route) -> R02 -> R05 -> R03 ->
 * R05 -> R06 -> R07. Without these accounts none of those gates is ever
 * actually hit. See TEST_PLAN.md for the script that uses them.
 *
 * Diagram-alignment redesign (see AGENT_NOTES.md): r09.secretary@ and
 * r10.diwan@ cover the two new receiving roles at receive_and_register
 * (R05/HR already existed as r05.manager@). r01.employee@'s `manager_id` is
 * wired to r02.reviewer@ below so the new front-half stages are walkable
 * end to end with a real assigned manager, not only through the R08
 * fallback — without that wiring, every manual walk of `submit ->
 * direct_manager_review` would fall through to the admin override and the
 * manager-specific checks (and the manager's own notification) would never
 * actually be exercised by hand.
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
    /** Every account below is created with this password. */
    public const PASSWORD = 'password';

    /**
     * [email, Arabic name, English label, role codes, department code, phone, is_active]
     *
     * PUBLIC because DevTestUserController serves this same list to the login
     * screen's one-click picker when APP_ENV=local. The picker used to carry its
     * own copy, which meant two lists that had to be kept in step by hand; this
     * is the one definition of the test accounts. The English label is the only
     * field here the database never sees — it exists purely so that picker can
     * label a row when the UI is in English.
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
     * @var list<array{0: string, 1: string, 2: string, 3: list<string>, 4: string, 5: string, 6: bool}>
     */
    public const TEST_USERS = [
        ['r01.employee@abusaleem.test',   'موظف تجريبي',             'Employee',                     ['R01'],        'ENG', '+218910000001', true],
        ['r02.reviewer@abusaleem.test',   'مقرر تجريبي',             'Reviewer',                     ['R02'],        'REP', '+218910000002', true],
        ['r03.head@abusaleem.test',       'رئيس اللجنة التجريبي',    'Committee head',               ['R03'],        'CMT', '+218910000003', true],
        ['r04.member1@abusaleem.test',    'عضو اللجنة الأول',        'Committee member 1',           ['R04'],        'CMT', '+218910000004', true],
        ['r04.member2@abusaleem.test',    'عضو اللجنة الثاني',       'Committee member 2',           ['R04'],        'CMT', '+218910000005', true],
        ['r04.member3@abusaleem.test',    'عضو اللجنة الثالث',       'Committee member 3',           ['R04'],        'CMT', '+218910000006', true],
        ['r05.manager@abusaleem.test',    'مدير الشؤون الإدارية',    'Admin affairs manager',        ['R05'],        'ADM', '+218910000007', true],
        ['r06.ministry@abusaleem.test',   'مندوب وزارة الحكم المحلي', 'Ministry delegate',            ['R06'],        'ABS', '+218910000008', true],
        ['r07.director@abusaleem.test',   'المدير العام التجريبي',   'Director general',             ['R07'],        'ABS', '+218910000009', true],
        ['r08.sysadmin@abusaleem.test',   'مدير نظام تجريبي',        'System admin',                 ['R08'],        'ADM', '+218910000010', true],
        ['multi.role@abusaleem.test',     'رئيس وعضو لجنة',          'Head + member (union check)',  ['R03', 'R04'], 'CMT', '+218910000011', true],
        ['inactive.user@abusaleem.test',  'مستخدم موقوف',            'Suspended user',               ['R01'],        'FIN', '+218910000012', false],
        // Diagram-alignment redesign — the two new receiving roles at
        // receive_and_register (R05/HR already existed as r05.manager@).
        ['r09.secretary@abusaleem.test',  'أمين سر اللجنة التجريبي', 'Committee secretary',          ['R09'],        'CMT', '+218910000013', true],
        ['r10.diwan@abusaleem.test',      'وكيل الديوان التجريبي',   'Diwan deputy',                 ['R10'],        'ABS', '+218910000014', true],
        // Stage 68 — [D] Art. 21's العضو القانوني, the one role the
        // pre-meeting legal review can be recorded by.
        ['r11.legal@abusaleem.test',      'العضو القانوني التجريبي',  'Legal officer',                ['R11'],        'CMT', '+218910000015', true],
    ];

    public function run(): void
    {
        // Both keyed by `code`, never by id: ids are only stable on a database
        // that was seeded from empty, and these seeders are all idempotent
        // upserts precisely so that assumption isn't needed.
        $departments = Department::query()->get()->keyBy('code');
        $roles = Role::query()->get()->keyBy('code');

        // Keyed by email so the manager-wiring step below can look up an
        // already-fetched model instead of re-querying.
        $users = [];

        // The English label is metadata for the login picker only — it has no
        // column, so it is skipped here with a bare comma.
        foreach (self::TEST_USERS as [$email, $name, , $roleCodes, $departmentCode, $phone, $isActive]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make(self::PASSWORD),
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

            $users[$email] = $user;
        }

        // Diagram-alignment redesign (see AGENT_NOTES.md): wire a real
        // direct-manager link so `submit -> direct_manager_review ->
        // administrative_routing` is walkable end to end as an actual
        // assigned manager, not only through the R08 fallback. r02.reviewer@
        // doubles as the manager here rather than growing the roster with a
        // dedicated account for one relationship — WorkflowService's
        // manager-gated rows check the `manager_id` link only, never role, so
        // this does not change anything r02.reviewer@ can already do.
        $users['r01.employee@abusaleem.test']->manager_id = $users['r02.reviewer@abusaleem.test']->id;
        $users['r01.employee@abusaleem.test']->save();
    }
}
