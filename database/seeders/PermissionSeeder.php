<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Permission catalogue + role assignments, mirroring the Role Matrix.
     * Each entry: key => [name_ar, name_en, group, [role codes that hold it]].
     *
     * NB: This is the coarse RBAC layer. The fine-grained screen×action grid
     * lives in screen_role_permissions (Stage 3 / 8) and is the source of truth
     * for UI/API enforcement in Stage 9.
     */
    public function run(): void
    {
        $catalogue = [
            // key                       name_ar                         name_en                     group          roles
            'transactions.add'        => ['إضافة معاملة جديدة',          'Add transaction',          'transactions', ['R01', 'R08']],
            'transactions.view'       => ['عرض المعاملات',               'View transactions',        'transactions', ['R01', 'R02', 'R03', 'R04', 'R05', 'R06', 'R07', 'R08']],
            'transactions.edit'       => ['تعديل المعاملة',              'Edit transaction',         'transactions', ['R01', 'R02', 'R05', 'R08']],
            'transactions.delete'     => ['حذف المعاملة',                'Delete transaction',       'transactions', ['R08']],
            'transactions.notes'      => ['إضافة ملاحظات',               'Add notes',                'transactions', ['R01', 'R02', 'R03', 'R04', 'R05', 'R08']],
            'transactions.attachments'=> ['رفع / تنزيل المرفقات',        'Manage attachments',       'transactions', ['R01', 'R02', 'R03', 'R04', 'R05', 'R08']],
            'transactions.forward'    => ['اعتماد / إرسال للمعالجة',      'Forward for processing',   'workflow',     ['R02', 'R03', 'R05', 'R06', 'R07', 'R08']],
            'decisions.approve'       => ['اعتماد القرار',               'Approve decision',         'workflow',     ['R03', 'R04', 'R05', 'R06', 'R07', 'R08']],
            'decisions.final_approve' => ['الاعتماد النهائي',            'Final approval',           'workflow',     ['R06', 'R07', 'R08']],
            'users.manage'            => ['إدارة المستخدمين',            'Manage users',             'admin',        ['R08']],
            'roles.manage'            => ['إدارة الصلاحيات',             'Manage permissions',       'admin',        ['R08']],
            'reports.view'            => ['التقارير والإحصائيات',        'Reports & statistics',     'reports',      ['R01', 'R02', 'R03', 'R04', 'R05', 'R06', 'R07', 'R08']],
            'audit.view'              => ['سجل التدقيق',                 'Audit log',                'audit',        ['R01', 'R02', 'R03', 'R04', 'R05', 'R06', 'R07', 'R08']],
            'settings.manage'         => ['إعدادات النظام',              'System settings',          'admin',        ['R08']],
        ];

        $rolesByCode = Role::all()->keyBy('code');

        foreach ($catalogue as $key => [$nameAr, $nameEn, $group, $roleCodes]) {
            $permission = Permission::updateOrCreate(
                ['key' => $key],
                ['name_ar' => $nameAr, 'name_en' => $nameEn, 'group' => $group],
            );

            $roleIds = collect($roleCodes)
                ->map(fn ($code) => $rolesByCode[$code]?->id)
                ->filter()
                ->all();

            $permission->roles()->sync($roleIds);
        }
    }
}
