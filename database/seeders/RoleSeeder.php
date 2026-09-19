<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the system roles, R01 onward. The first eight come from the Role
 * Matrix sheet; R09/R10 were added by the diagram-alignment redesign, R11 by
 * Stage 68, and R12 by Stage 87 — so treat the array below as the count, not
 * this sentence.
 *
 * These roles are reference data fixed by the municipality's approval
 * structure, not user-editable content — but "fixed" means an administrator
 * cannot add one through the UI, NOT that the list is closed: three have been
 * appended since it was first written. Everything downstream keys off the
 * `code`, so this seeder must run FIRST (see DatabaseSeeder).
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
            ['code' => 'R05', 'name_ar' => 'مدير إدارة الشؤون الإدارية', 'name_en' => 'Admin Manager', 'description' => 'يدقق ويعتمد الطلبات حسب الصلاحيات'],
            ['code' => 'R06', 'name_ar' => 'وزارة الحكم المحلي', 'name_en' => 'Ministry', 'description' => 'تراجع وتدقق الطلبات قبل الاعتماد النهائي'],
            ['code' => 'R07', 'name_ar' => 'المدير العام / العميد', 'name_en' => 'Director / Dean', 'description' => 'يعتمد الطلبات اعتماداً نهائياً على مستوى البلدية'],
            ['code' => 'R08', 'name_ar' => 'مدير النظام', 'name_en' => 'System Admin', 'description' => 'إدارة النظام والمستخدمين والصلاحيات والإعدادات'],
            // Diagram-alignment roles — see AGENT_NOTES.md for the source
            // (the "Employee Affairs Committee request" infographic). Additive
            // only: RoleSeeder upserts on `code`, so nothing above is affected.
            ['code' => 'R09', 'name_ar' => 'أمين سر اللجنة', 'name_en' => 'Committee Secretary', 'description' => 'يستلم الملف بعد اكتمال الدراسة ويُعِدّ جدول أعمال اللجنة'],
            ['code' => 'R10', 'name_ar' => 'وكيل الديوان', 'name_en' => 'Diwan Deputy', 'description' => 'أحد مسارات الإحالة الإدارية من المدير المباشر'],
            // Stage 68 (Track K) — [D] Art. 21 / Art. 14 (ب)'s العضو القانوني.
            // Stage 45 gave the committee a `legal` SEAT, but Art. 109 and
            // Appendix 45 both list the legal officer as a system ROLE with
            // its own permissions, which is what was missing.
            ['code' => 'R11', 'name_ar' => 'العضو القانوني', 'name_en' => 'Legal Officer', 'description' => 'يراجع السند القانوني والاختصاص وسلامة المستندات قبل عرض الملف على اللجنة'],
            // Stage 87 (Track M) — [F] names إدارة الموارد البشرية twice: as
            // the route_to_hr destination, and as co-owner of the study at
            // `observations`. Neither previously belonged to a distinct role
            // — the routing destination sat with R05 (مدير إدارة الشؤون
            // الإدارية, a different administrative duty), and the study had
            // no HR party at all. R12 takes over the first outright and gains
            // a bounded, non-controlling share of the second (see
            // RequestVisibility). See AGENT_NOTES.md for the decision.
            ['code' => 'R12', 'name_ar' => 'مدير إدارة الموارد البشرية', 'name_en' => 'HR Manager', 'description' => 'يستلم الطلبات المحالة لإدارة الموارد البشرية ويشارك في دراسة الطلب قبل عرضه على اللجنة'],
        ];

        foreach ($roles as $role) {
            // updateOrCreate keyed on `code` makes this seeder idempotent:
            // re-running it refreshes the names without creating duplicates or
            // changing role ids (which other tables reference).
            Role::updateOrCreate(['code' => $role['code']], $role);
        }
    }
}
