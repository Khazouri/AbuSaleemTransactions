<?php

namespace Database\Seeders;

use App\Models\WalkthroughVideo;
use Illuminate\Database\Seeder;

// Learning centre — narrated walkthroughs.
/**
 * The clip catalogue: one row per walkthrough, in the order the centre lists
 * them. Writes the descriptive columns only, never the file columns, so a
 * re-seed (also run by «بعد الرفع») renames a clip without losing its upload;
 * it deletes nothing, because R08 may already have attached a file to a row
 * this list later drops.
 *
 * A screen clip shows reading only, so it is true for everyone who may open
 * the screen. Each write is its own clip naming that action, and a workflow
 * act names `roles`, because those are granted per role by
 * workflow_transitions and not by the permission grid. `workflow_designer` is
 * listed although the designer is off by default: the row stays invisible
 * until a file is attached, and the screen's grant is cleared while the flag
 * is off.
 */
class WalkthroughVideoSeeder extends Seeder
{
    /**
     * [code, screen_code, action, roles|null, title_ar, title_en, description_ar]
     *
     * @var list<array{0: string, 1: string, 2: string, 3: list<string>|null, 4: string, 5: string, 6: string}>
     */
    public const VIDEOS = [
        // --- Screens -----------------------------------------------------------
        ['screen.login', 'dashboard', 'view', null, 'تسجيل الدخول', 'Signing in', 'كيف تدخل إلى النظام بحسابك، وكيف تدخل عبر نظام الموارد البشرية (ERP) إن كان مفعّلًا.'],
        ['screen.dashboard', 'dashboard', 'view', null, 'لوحة التحكم الرئيسية', 'The dashboard', 'نظرة على المؤشرات والأرقام التي تظهر أول ما تفتح النظام، وما تعنيه كل بطاقة.'],
        ['screen.my_tasks', 'my_tasks', 'view', null, 'المهام المعلقة', 'Pending tasks', 'كيف تعرف ما ينتظر منك، وكيف تفتح كل مهمة من القائمة مباشرة.'],
        ['screen.request_intake', 'request_intake', 'add', null, 'إرسال طلب جديد', 'Filing a new request', 'خطوات تعبئة الطلب وإرفاق المستندات المطلوبة ثم مراجعته وإرساله.'],
        ['screen.request_tracking', 'request_tracking', 'view', null, 'متابعة طلباتي', 'Following my requests', 'كيف تتابع حالة طلباتك والمرحلة التي وصلت إليها.'],
        ['screen.requests', 'requests', 'view', null, 'قائمة الطلبات', 'The requests list', 'البحث في الطلبات وتصفيتها وفتح أي طلب منها.'],
        ['screen.appeals', 'appeals', 'view', null, 'التظلمات', 'Appeals', 'كيف تقرأ قائمة التظلمات وتتابع حالة كل تظلم.'],
        ['screen.request_details', 'request_details', 'view', null, 'تفاصيل الطلب', 'Reading a request', 'التبويبات والخط الزمني والمرفقات في صفحة الطلب.'],
        ['screen.notes_attachments', 'notes_attachments', 'view', null, 'الملاحظات والمرفقات', 'Notes and attachments', 'كيف تقرأ ملاحظات الطلب ومرفقاته وتعاينها.'],
        ['screen.meetings_dashboard', 'meetings_dashboard', 'view', null, 'لوحة قيادة الاجتماعات', 'The meetings dashboard', 'ملخص عمل اللجنة: الطلبات المنتظرة والاجتماعات القادمة.'],
        ['screen.committee_candidates', 'committee_candidates', 'view', null, 'الطلبات المرشحة', 'Committee candidates', 'قائمة الطلبات التي تنتظر عرضها على اللجنة وكيف تُرتّب.'],
        ['screen.legal_review', 'legal_review', 'view', null, 'المراجعة القانونية', 'Legal review', 'الطلبات التي تنتظر رأي العضو القانوني وما سُجّل عليها.'],
        ['screen.meetings', 'meetings', 'view', null, 'الاجتماعات', 'Meetings', 'قائمة الاجتماعات وصفحة الاجتماع: الموعد والحضور وجدول الأعمال.'],
        ['screen.meeting_agenda', 'meeting_agenda', 'view', null, 'جدول الأعمال', 'The meeting agenda', 'كيف يُقرأ جدول أعمال الاجتماع وبنوده.'],
        ['screen.decisions', 'decisions', 'view', null, 'القرارات والتوصيات', 'Decisions and recommendations', 'كيف تبحث في القرارات والتوصيات المسجلة وتقرأها.'],
        ['screen.registers', 'registers', 'view', null, 'السجلات الرسمية', 'Official registers', 'السجلات الرسمية للجنة وكيف تتصفحها.'],
        ['screen.reports', 'reports', 'view', null, 'التقارير والإحصائيات', 'Reports and statistics', 'قراءة المؤشرات وتصفيتها حسب الفترة والنوع.'],
        ['screen.audit_log', 'audit_log', 'view', null, 'سجل التدقيق', 'The audit log', 'كيف تبحث في سجل التغييرات وتعرف من غيّر ماذا ومتى.'],
        ['screen.users', 'users', 'view', null, 'المستخدمون', 'Users', 'قائمة المستخدمين وعرضها حسب الإدارة وفتح حساب أحدهم.'],
        ['screen.departments', 'departments', 'view', null, 'الإدارات والأقسام', 'Departments', 'الهيكل التنظيمي للإدارات وما يمكن فعله بكل إدارة.'],
        ['screen.roles_permissions', 'roles_permissions', 'view', null, 'الأدوار والصلاحيات', 'Roles and permissions', 'جدول الصلاحيات: ما يستطيعه كل دور في كل شاشة.'],
        ['screen.committee_roles', 'committee_roles', 'view', null, 'المناصب الفردية', 'Sole-holder posts', 'من يشغل كل منصب فردي في اللجنة.'],
        ['screen.request_types', 'request_types', 'view', null, 'أنواع الطلبات وقائمة المستندات', 'Request types and the documents list', 'أنواع الطلبات وقواعد كل نوع، وقائمة المستندات التي تُختار منها مستندات كل نوع.'],
        ['screen.workflow_designer', 'workflow_designer', 'view', null, 'مسارات العمل', 'Workflows', 'رسم مسار العمل ومراحله وكيف يُقرأ.'],
        ['screen.settings', 'settings', 'view', null, 'الإعدادات العامة', 'General settings', 'الإعدادات التي تحكم عمل النظام وما يفعله كل إعداد.'],
        ['screen.backup', 'backup', 'view', null, 'النسخ الاحتياطي', 'Backup', 'النسخ الاحتياطية المتوفرة وكيف تُقرأ.'],
        ['screen.maintenance', 'maintenance', 'view', null, 'الصيانة والنشر', 'Maintenance and deployment', 'أوامر الصيانة، وزر «بعد الرفع» الذي يُضغط بعد كل رفع للنسخة.'],
        ['screen.notifications', 'notifications', 'view', null, 'الإشعارات', 'Notifications', 'كيف تقرأ إشعاراتك وتفتح ما يخصها.'],
        ['screen.user_guide', 'user_guide', 'view', null, 'دليل الاستخدام', 'The user guide', 'البحث في مقالات الدليل وقراءتها.'],
        ['screen.learning_center', 'learning_center', 'view', null, 'مركز التعلم', 'The learning centre', 'كيف تجد الشروحات المصورة وتشاهدها، وزر «شاهد الشرح» في كل شاشة.'],

        // --- Role tasks --------------------------------------------------------
        ['task.manager_review', 'request_details', 'view', [WalkthroughVideo::MANAGER], 'مراجعة المدير المباشر للطلب', "The direct manager's review", 'كيف يحيل المدير طلب موظفه أو يعيده إليه أو يلغيه، وملاحظته اختيارية.'],
        ['task.hr_prepare_file', 'request_details', 'view', ['R12'], 'تجهيز الملف الوظيفي', 'Preparing the employment file', 'معاينة كل مستند، ثم تجهيز الملف الوظيفي وتسجيل الطلب.'],
        ['task.hr_archive_service_file', 'request_details', 'view', ['R12'], 'أرشفة ملف الخدمة', 'Archiving the service file', 'أرشفة ملف الخدمة بعد قرار جهة الاعتماد، وبها يُقفل الطلب.'],
        ['task.rapporteur_qaid', 'request_details', 'view', ['R02'], 'القيد: اختبار الاختصاص والبوابة الأولى', 'The entry check', 'بطاقة القيد: اختبار الاختصاص وفحص اكتمال المستندات في إرسال واحد.'],
        ['task.rapporteur_form_committee', 'meetings', 'view', ['R02'], 'تشكيل اللجنة', 'Forming a committee', 'كيف يشكّل المقرر اللجنة من أعضاء المقاعد الخمسة.'],
        ['task.rapporteur_schedule_meeting', 'meetings', 'view', ['R02'], 'تحديد موعد الاجتماع ودعوة الضيوف', 'Scheduling a meeting and inviting guests', 'اقتراح موعد الاجتماع ودعوة الأعضاء وإضافة ضيوف بصفاتهم.'],
        ['task.rapporteur_build_agenda', 'meetings', 'view', ['R02'], 'إعداد جدول الأعمال وطباعته', 'Building and printing the agenda', 'إضافة البنود من قائمة الطلبات المرشحة ثم طباعة جدول الأعمال.'],
        ['task.rapporteur_record_results', 'meetings', 'view', ['R02'], 'تسجيل نتائج الاجتماع', 'Recording the results', 'إدخال توصية كل بند وجهة الاعتماد وإرفاق المحضر الممسوح في إرسال واحد.'],
        ['task.rapporteur_rollback_results', 'meetings', 'view', ['R02'], 'التراجع عن نتائج الاجتماع', 'Rolling the results back', 'متى يمكن التراجع عن النتائج وماذا يحدث للملفات.'],
        ['task.rapporteur_confirm_decisions', 'meetings', 'view', ['R02'], 'تأكيد قرارات جهة الاعتماد', 'Confirming authority decisions', 'مراجعة قرارات جهة الاعتماد وتأكيد عدة ملفات دفعة واحدة.'],
        ['task.rapporteur_return_decision', 'request_details', 'view', ['R02'], 'إعادة قرار إلى جهة الاعتماد', 'Returning a decision to the authority', 'إعادة ملف إلى جهة الاعتماد مع ملاحظة مطلوبة.'],
        ['task.legal_recommendation', 'legal_review', 'view', ['R11'], 'توصية العضو القانوني', "The legal member's recommendation", 'إدخال التوصية وجهة الاعتماد للطلب.'],
        ['task.answer_meeting_invitation', 'meetings', 'view', null, 'الرد على دعوة اجتماع', 'Answering a meeting invitation', 'قبول موعد الاجتماع المقترح أو الاعتذار عنه.'],
        ['task.authority_decide', 'meetings', 'view', ['R07', 'R06'], 'قرار جهة الاعتماد من صفحة الاجتماع', 'Deciding files from the meeting page', 'اعتماد أو رفض عدة ملفات بقرار ورقي واحد.'],
        ['task.employee_file_appeal', 'appeals', 'add', ['R01'], 'تقديم تظلم', 'Filing an appeal', 'كيف يقدّم الموظف تظلمًا على قرار صدر في طلبه.'],
        ['task.employee_respond_returned', 'request_details', 'view', ['R01'], 'الرد على طلب أُعيد إليك', 'Responding to a returned request', 'استكمال النقص وإعادة إرسال الطلب بعد إعادته.'],
        ['task.admin_name_sole_posts', 'committee_roles', 'view', ['R08'], 'تسمية شاغلي المناصب الفردية', 'Naming the sole-holder posts', 'تسمية المقرر والعميد والوزير في «المناصب الفردية».'],
    ];

    public function run(): void
    {
        foreach (self::VIDEOS as $i => [$code, $screen, $action, $roles, $titleAr, $titleEn, $descriptionAr]) {
            WalkthroughVideo::updateOrCreate(['code' => $code], [
                'title_ar' => $titleAr,
                'title_en' => $titleEn,
                'description_ar' => $descriptionAr,
                'screen_code' => $screen,
                'action' => $action,
                'roles' => $roles,
                'sort_order' => $i + 1,
            ]);
        }
    }
}
