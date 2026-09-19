<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the coarse capability catalogue and attaches each capability to the
 * roles that hold it, mirroring the Role Matrix sheet.
 *
 * Reading the table below: each row is one capability, and the last column
 * lists every role allowed to use it. A role NOT listed simply doesn't get it
 * (least privilege) — e.g. only R08 may delete a request, and only
 * R06/R07/R08 may give final approval.
 *
 * Note the matrix sheet distinguishes "full" from "limited" permission
 * (green vs amber). This table can only say yes/no, so both are seeded as yes;
 * the finer distinction is expressed per-screen in screen_role_permissions.
 *
 * Runs after RoleSeeder, since it looks roles up by code.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // key => [name_ar, name_en, UI group, roles that hold it]
        $catalogue = [
            // --- Working with requests -----------------------------------
            // Diagram-alignment redesign (see AGENT_NOTES.md): R09/R10 are
            // added everywhere R05 already appears, since they receive and
            // register requests at the new front-half stages exactly like
            // R05/HR does for its own routed path. Note this catalogue is not
            // read by any enforcement code any more (screen_role_permissions
            // has been the real source of truth since Stage 9) — kept in step
            // anyway so it stays an accurate reference rather than stale data.
            //
            // Stage 87 — R12 (HR Manager) joins the same rows R09/R10 do: it
            // is the third registration destination now (R05's replacement on
            // the HR route), so it holds the same shape of capability they do.
            'requests.add' => ['إضافة طلب جديد',          'Add request',          'requests', ['R01', 'R08']],
            'requests.view' => ['عرض الطلبات',               'View requests',        'requests', ['R01', 'R02', 'R03', 'R04', 'R05', 'R06', 'R07', 'R08', 'R09', 'R10', 'R12']],
            'requests.edit' => ['تعديل الطلب',              'Edit request',         'requests', ['R01', 'R02', 'R05', 'R08', 'R09', 'R10', 'R12']],
            'requests.delete' => ['حذف الطلب',                'Delete request',       'requests', ['R08']],
            'requests.notes' => ['إضافة ملاحظات',               'Add notes',                'requests', ['R01', 'R02', 'R03', 'R04', 'R05', 'R08', 'R09', 'R10', 'R12']],
            'requests.attachments' => ['رفع / تنزيل المرفقات',        'Manage attachments',       'requests', ['R01', 'R02', 'R03', 'R04', 'R05', 'R08', 'R09', 'R10', 'R12']],

            // --- Moving them through the workflow ----------------------------
            // R01 is absent: an employee submits a request but never advances it.
            'requests.forward' => ['اعتماد / إرسال للمعالجة',      'Forward for processing',   'workflow',     ['R02', 'R03', 'R05', 'R06', 'R07', 'R08', 'R09', 'R10', 'R12']],
            'decisions.approve' => ['اعتماد القرار',               'Approve decision',         'workflow',     ['R03', 'R04', 'R05', 'R06', 'R07', 'R08']],
            // The last word on a request — ministry, dean, or sysadmin only.
            'decisions.final_approve' => ['الاعتماد النهائي',            'Final approval',           'workflow',     ['R06', 'R07', 'R08']],

            // --- Administration (System Admin only) --------------------------
            'users.manage' => ['إدارة المستخدمين',            'Manage users',             'admin',        ['R08']],
            'roles.manage' => ['إدارة الصلاحيات',             'Manage permissions',       'admin',        ['R08']],
            'settings.manage' => ['إعدادات النظام',              'System settings',          'admin',        ['R08']],

            // --- Visibility, granted broadly ---------------------------------
            'reports.view' => ['التقارير والإحصائيات',        'Reports & statistics',     'reports',      ['R01', 'R02', 'R03', 'R04', 'R05', 'R06', 'R07', 'R08', 'R09', 'R10', 'R12']],
            'audit.view' => ['سجل التدقيق',                 'Audit log',                'audit',        ['R01', 'R02', 'R03', 'R04', 'R05', 'R06', 'R07', 'R08', 'R09', 'R10', 'R12']],
        ];

        // Fetch every role once and index by code, so the loop below does no
        // extra queries.
        $rolesByCode = Role::all()->keyBy('code');

        foreach ($catalogue as $key => [$nameAr, $nameEn, $group, $roleCodes]) {
            $permission = Permission::updateOrCreate(
                ['key' => $key],
                ['name_ar' => $nameAr, 'name_en' => $nameEn, 'group' => $group],
            );

            // Translate role codes into ids, skipping any that don't exist.
            $roleIds = collect($roleCodes)
                ->map(fn ($code) => $rolesByCode[$code]?->id)
                ->filter()
                ->all();

            // sync() replaces the permission's role list outright, so removing
            // a code from the array above actually revokes it on re-seed.
            $permission->roles()->sync($roleIds);
        }
    }
}
