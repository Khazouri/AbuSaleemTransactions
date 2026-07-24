<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the eight system roles (R01–R08) taken from the Role Matrix sheet.
 *
 * These roles are fixed by the municipality's approval structure — they are
 * reference data, not user-editable content. Everything downstream keys off
 * the `code`, so this seeder must run FIRST (see DatabaseSeeder).
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // [code, Arabic name, English name, what the role does]
        $roles = [
            ['code' => 'R01', 'name_ar' => 'موظف', 'name_en' => 'Employee', 'description' => 'يقدم الطلب ويتابع حالته ويستلم القرارات'],
            ['code' => 'R02', 'name_ar' => 'المقرر', 'name_en' => 'Reviewer', 'description' => 'يراجع الطلبات ويضيف الملاحظات والتوصيات'],
            ['code' => 'R03', 'name_ar' => 'رئيس اللجنة', 'name_en' => 'Committee Head', 'description' => 'يعتمد دخول الطلب إلى اللجنة ويصدر القرارات'],
            ['code' => 'R04', 'name_ar' => 'عضو اللجنة', 'name_en' => 'Committee Member', 'description' => 'يشارك في الاجتماع ويصوت على القرارات'],
            ['code' => 'R05', 'name_ar' => 'مدير إدارة الشؤون الإدارية', 'name_en' => 'Admin Manager', 'description' => 'يدقق ويعتمد المعاملات حسب الصلاحيات'],
            ['code' => 'R06', 'name_ar' => 'وزارة الحكم المحلي', 'name_en' => 'Ministry', 'description' => 'تراجع وتدقق المعاملات قبل الاعتماد النهائي'],
            ['code' => 'R07', 'name_ar' => 'المدير العام / العميد', 'name_en' => 'Director / Dean', 'description' => 'يعتمد المعاملات اعتماداً نهائياً على مستوى البلدية'],
            ['code' => 'R08', 'name_ar' => 'مدير النظام', 'name_en' => 'System Admin', 'description' => 'إدارة النظام والمستخدمين والصلاحيات والإعدادات'],
        ];

        foreach ($roles as $role) {
            // updateOrCreate keyed on `code` makes this seeder idempotent:
            // re-running it refreshes the names without creating duplicates or
            // changing role ids (which other tables reference).
            Role::updateOrCreate(['code' => $role['code']], $role);
        }
    }
}
