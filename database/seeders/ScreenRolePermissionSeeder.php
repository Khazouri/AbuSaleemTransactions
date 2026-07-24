<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Screen;
use App\Models\ScreenRolePermission;
use Illuminate\Database\Seeder;

class ScreenRolePermissionSeeder extends Seeder
{
    /**
     * Baseline 22x8 matrix derived from the Role Matrix sheet.
     *
     * Rules:
     *  - R08 (System Admin) gets every action on every screen.
     *  - Other roles get only what is listed below; '*' means all roles.
     *  - Anything unlisted stays false (least privilege).
     *
     * This is a starting point — the Stage 8 grid edits these rows, and Stage 9
     * enforces them on both the API and the Vue router.
     */
    private const DEFAULTS = [
        'dashboard'               => ['view' => '*', 'print' => '*'],
        'transactions'            => ['view' => '*', 'print' => '*', 'export' => ['R06', 'R07']],
        'transaction_intake'      => ['view' => ['R01', 'R02', 'R03', 'R04', 'R05', 'R06'], 'add' => ['R01', 'R02', 'R03', 'R04', 'R05', 'R06'], 'edit' => ['R01', 'R02', 'R05']],
        'transaction_details'     => ['view' => '*', 'print' => '*', 'export' => '*'],
        'notes_attachments'       => ['view' => '*', 'add' => ['R01', 'R02', 'R03', 'R04', 'R05'], 'edit' => ['R01', 'R02']],
        'meetings'                => ['view' => '*', 'add' => ['R03', 'R04'], 'edit' => ['R03'], 'print' => '*'],
        'decisions'               => ['view' => '*', 'add' => ['R03', 'R04'], 'approve' => ['R03'], 'print' => '*'],
        'reviewer_approval'       => ['view' => ['R02'], 'approve' => ['R02']],
        'committee_head_approval' => ['view' => ['R03'], 'approve' => ['R03']],
        'admin_manager_approval'  => ['view' => ['R05'], 'approve' => ['R05']],
        'ministry_approval'       => ['view' => ['R06'], 'approve' => ['R06']],
        'authority_approval'      => ['view' => ['R07'], 'approve' => ['R07']],
        'final_approval'          => ['view' => ['R07'], 'approve' => ['R07']],
        'users'                   => [],
        'roles_permissions'       => [],
        'settings'                => [],
        'reports'                 => ['view' => '*', 'print' => '*', 'export' => ['R06', 'R07']],
        'audit_log'               => ['view' => '*', 'export' => ['R06', 'R07']],
        'notifications'           => ['view' => '*'],
        'templates'               => [],
        'backup'                  => [],
        'user_guide'              => ['view' => '*', 'print' => '*'],
    ];

    /** action key => column name */
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
            $grants = self::DEFAULTS[$screen->code] ?? [];

            foreach ($roles as $role) {
                $flags = array_fill_keys(array_values(self::COLUMNS), false);

                if ($role->code === 'R08') {
                    // System Admin: full access everywhere.
                    $flags = array_fill_keys(array_values(self::COLUMNS), true);
                } else {
                    foreach ($grants as $action => $allowed) {
                        if ($allowed === '*' || in_array($role->code, (array) $allowed, true)) {
                            $flags[self::COLUMNS[$action]] = true;
                        }
                    }
                }

                ScreenRolePermission::updateOrCreate(
                    ['screen_id' => $screen->id, 'role_id' => $role->id],
                    $flags,
                );
            }
        }
    }
}
