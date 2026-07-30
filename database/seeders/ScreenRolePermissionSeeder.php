<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Screen;
use App\Models\ScreenRolePermission;
use Illuminate\Database\Seeder;

/**
 * Builds the starting permission matrix: every screen x every role
 * (23 x 8 = 184 rows), each with seven action flags.
 *
 * How the rules below are applied:
 *   - R08 (System Admin) is granted every action on every screen.
 *   - Every other role starts with NOTHING and is granted only what the
 *     DEFAULTS table lists — least privilege by construction.
 *   - '*' means "all roles".
 *
 * The shape of the data is deliberate. Notice the approval screens: each names
 * exactly one role, so اعتماد وزارة الحكم المحلي is visible and actionable only
 * to R06. That is the segregation of duties the whole approval chain rests on.
 *
 * This is a STARTING POINT, not the final configuration — Stage 8 gives admins
 * a grid to edit these rows, and Stage 9 enforces whatever they end up as.
 *
 * Runs last: needs both roles and screens to exist.
 */
class ScreenRolePermissionSeeder extends Seeder
{
    /**
     * screen code => [action => roles allowed]
     * Any action not listed for a screen stays false for every role but R08.
     */
    private const DEFAULTS = [
        // Everyone needs the dashboard and the transaction list.
        'dashboard'               => ['view' => '*', 'print' => '*'],
        'transactions'            => ['view' => '*', 'print' => '*', 'export' => ['R06', 'R07']],

        // Intake: the roles that actually register incoming paperwork.
        // R07 is absent — the dean approves, they don't do data entry.
        'transaction_intake'      => ['view' => ['R01', 'R02', 'R03', 'R04', 'R05', 'R06'], 'add' => ['R01', 'R02', 'R03', 'R04', 'R05', 'R06'], 'edit' => ['R01', 'R02', 'R05']],
        'transaction_details'     => ['view' => '*', 'print' => '*', 'export' => '*'],

        // Notes/attachments: broad read, narrower write.
        'notes_attachments'       => ['view' => '*', 'add' => ['R01', 'R02', 'R03', 'R04', 'R05'], 'edit' => ['R01', 'R02']],

        // Committee work belongs to the committee roles (R03 head, R04 member).
        'meetings'                => ['view' => '*', 'add' => ['R03', 'R04'], 'edit' => ['R03'], 'print' => '*'],
        'decisions'               => ['view' => '*', 'add' => ['R03', 'R04'], 'approve' => ['R03'], 'print' => '*'],

        // One approval screen per authority — single-role by design, so no one
        // can approve at a level that isn't theirs.
        'reviewer_approval'       => ['view' => ['R02'], 'approve' => ['R02']],
        'committee_head_approval' => ['view' => ['R03'], 'approve' => ['R03']],
        'admin_manager_approval'  => ['view' => ['R05'], 'approve' => ['R05']],
        'ministry_approval'       => ['view' => ['R06'], 'approve' => ['R06']],
        'authority_approval'      => ['view' => ['R07'], 'approve' => ['R07']],
        'final_approval'          => ['view' => ['R07'], 'approve' => ['R07']],

        // Administration: empty array = R08 only.
        'users'                   => [],
        'departments'             => [],
        'roles_permissions'       => [],
        'settings'                => [],
        'templates'               => [],
        'backup'                  => [],

        // Oversight: visible to all, exportable only by the senior roles.
        'reports'                 => ['view' => '*', 'print' => '*', 'export' => ['R06', 'R07']],
        'audit_log'               => ['view' => '*', 'export' => ['R06', 'R07']],
        'notifications'           => ['view' => '*'],
        'user_guide'              => ['view' => '*', 'print' => '*'],
    ];

    /** Maps the short action names used above to the real column names. */
    private const COLUMNS = [
        'view' => 'can_view',
        'add' => 'can_add',
        'edit' => 'can_edit',
        'delete' => 'can_delete',
        'approve' => 'can_approve',
        'print' => 'can_print',
        'export' => 'can_export',
    ];

    public function run(): void
    {
        $roles = Role::all();
        $screens = Screen::all();

        foreach ($screens as $screen) {
            // No entry for this screen means "R08 only".
            $grants = self::DEFAULTS[$screen->code] ?? [];

            foreach ($roles as $role) {
                // The unique(screen_id, role_id) index makes this a safe upsert:
                // re-seeding resets the matrix to these defaults.
                ScreenRolePermission::updateOrCreate(
                    ['screen_id' => $screen->id, 'role_id' => $role->id],
                    $this->flagsFor($role, $grants),
                );
            }
        }
    }

    /**
     * Work out the seven action flags for one role on one screen.
     *
     * @param  array<string, string|array<string>>  $grants  This screen's DEFAULTS entry
     * @return array<string, bool>                           Column name => allowed
     */
    private function flagsFor(Role $role, array $grants): array
    {
        // System Admin bypasses the table entirely: everything, everywhere.
        if ($role->code === 'R08') {
            return array_fill_keys(array_values(self::COLUMNS), true);
        }

        // Everyone else starts closed, then we open only what's granted.
        $flags = array_fill_keys(array_values(self::COLUMNS), false);

        foreach ($grants as $action => $allowed) {
            // Grant if the rule is '*' (all roles) or names this role's code.
            if ($allowed === '*' || in_array($role->code, (array) $allowed, true)) {
                $flags[self::COLUMNS[$action]] = true;
            }
        }

        return $flags;
    }
}
