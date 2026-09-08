<?php

namespace Database\Seeders;

use App\Models\Screen;
use Illuminate\Database\Seeder;

/**
 * Seeds the application screens, in the order they appear on the
 * screen-permissions sheet — originally 29 from that sheet (Stage 28 adds 7
 * to the original 22 for the meetings-unit redesign; Stage 57 later removes
 * one, `authority_approval`), plus `departments` (see the note beside it
 * below), Stage 58's `appeals`, Stage 68's `legal_review` and Stage 80's
 * `registers` = 32 total.
 *
 * Two consumers:
 *   - the sidebar (Stage 5) renders these rows, filtered by can_view
 *   - the permission matrix uses them as its rows
 *
 * IMPORTANT: the `code` values here must stay in step with the keys in
 * ScreenRolePermissionSeeder. A typo in either place silently leaves a screen
 * with no permissions, which looks like "the screen vanished for everyone".
 *
 * `route` mirrors the Vue router paths so the sidebar links and the Stage 9
 * route guard resolve to the same screen.
 */
class ScreenSeeder extends Seeder
{
    public function run(): void
    {
        // Stage 57 — the first screen this seeder has ever had to actually
        // remove, not just add/modify: dropping a row from the upsert array
        // below leaves it orphaned, since updateOrCreate() never deletes.
        // Its screen_role_permissions rows cascade-delete with it.
        Screen::query()->where('code', 'authority_approval')->delete();

        // [code, Arabic name, English name, Vue route, icon, group]
        // `group` is null for every ungrouped (flat, top-level) screen —
        // Stage 28 is the first to use it, on the Committee block below.
        $screens = [
            // --- Day-to-day work ---------------------------------------------
            ['dashboard',                'لوحة التحكم الرئيسية',       'Dashboard',                    '/dashboard',                'gauge',        null],
            ['requests',             'الطلبات',                  'Requests',                 '/requests',             'list',         null],
            ['request_intake',       'استلام الطلب',            'Request Intake',           '/requests/create',      'inbox',        null],
            ['request_details',      'تفاصيل الطلب',            'Request Details',          '/requests/:id',         'file-text',    null],
            ['notes_attachments',        'الملاحظات والمرفقات',        'Notes & Attachments',          '/requests/:id/notes',   'paperclip',    null],
            // Stage 58 — appeals (تظلمات) against an already-decided request.
            // Top-level and ungrouped on purpose: filing an appeal is an
            // employee-facing action, not committee administration, so it
            // does NOT sit inside meetings_management below (see
            // STAGE_PLAN.md Track J's intro).
            ['appeals',               'التظلمات',                'Appeals',                  '/appeals',              'flag',         null],

            // --- Committee (Stage 28: "إدارة الاجتماعات" / meetings_management) --
            // 8 grouped slots for Track H (stages 28-37). `meetings` already
            // existed (Stage 20) and is unchanged apart from gaining a
            // `group`; 6 more are empty navigation shells until later stages
            // give them real content. `decisions` (Stage 25) was grouped here
            // too until Stage 43 pulled it back out — [C] is explicit that
            // decisions is a shared system-wide unit, not a meetings-only one
            // ("وحدات مشتركة في النظام ولا نكررها داخل قسم الاجتماعات") — so it
            // stays in this array position for a stable diff but carries no
            // `group`, same as every other shared screen.
            ['meetings_dashboard',       'لوحة قيادة الاجتماعات',      'Meetings Dashboard',           '/meetings/dashboard',       'grid',         'meetings_management'],
            ['committee_candidates',     'الطلبات المرشحة',            'Committee Candidates',         '/meetings/candidates',      'file-plus',    'meetings_management'],
            // Stage 68 — [D] Art. 21's pre-meeting legal review. Grouped with
            // the committee block because it is agenda preparation ([E] stage
            // 08 sits between completeness and the presentation memo), not an
            // employee-facing screen like `appeals`.
            ['legal_review',             'المراجعة القانونية',          'Legal Review',                 '/meetings/legal-review',    'scale',        'meetings_management'],
            ['meetings',                 'الاجتماعات',                'Meetings',                     '/meetings',                 'users',        'meetings_management'],
            ['meeting_agenda',           'جدول الأعمال',               'Meeting Agenda',               '/meetings/agenda',          'file-text',    'meetings_management'],
            ['meeting_readiness',        'جاهزية الاجتماع',            'Meeting Readiness',            '/meetings/readiness',       'check-square', 'meetings_management'],
            ['meeting_live',             'مباشرة الاجتماع',            'Live Meeting',                 '/meetings/live',            'video',        'meetings_management'],
            // Stage 43 — decisions is shared, not part of meetings_management.
            ['decisions',                'القرارات والتوصيات',         'Decisions & Recommendations',  '/decisions',                'check-square', null],
            ['meeting_minutes',          'المحاضر',                    'Minutes',                      '/meetings/minutes',         'book',         'meetings_management'],
            ['meeting_outputs',          'المخرجات',                   'Outputs',                      '/meetings/outputs',         'bar-chart',    'meetings_management'],

            // --- The approval chain, one screen per authority ------------------
            ['reviewer_approval',        'اعتماد المقرر',              'Reviewer Approval',            '/approvals/reviewer',       'user-check',   null],
            ['committee_head_approval',  'اعتماد رئيس اللجنة',         'Committee Head Approval',      '/approvals/committee-head', 'award',        null],
            ['admin_manager_approval',   'اعتماد مدير الإدارة',        'Admin Manager Approval',       '/approvals/admin-manager',  'briefcase',    null],
            ['ministry_approval',        'اعتماد وزارة الحكم المحلي',   'Ministry Approval',            '/approvals/ministry',       'landmark',     null],
            // Stage 57 removed `authority_approval` (competent_authority) —
            // no standard document names a fourth post-committee approving
            // party; see the explicit delete() call below.
            ['final_approval',           'الاعتماد النهائي والأرشفة',   'Final Approval & Archiving',   '/approvals/final',          'archive',      null],

            // --- Administration ------------------------------------------------
            ['users',                    'المستخدمون',                'Users',                        '/users',                    'user',         null],
            // NOTE: `departments` is a 23rd screen, not on the original
            // 22-screen sheet. The system needs somewhere to manage the org
            // tree (Stage 6) and no existing screen covers it. Restricted to
            // R08 like the other administration screens.
            ['departments',              'الإدارات والأقسام',          'Departments',                  '/departments',              'sitemap',      null],
            ['roles_permissions',        'الأدوار والصلاحيات',         'Roles & Permissions',          '/roles',                    'key',          null],
            ['settings',                 'الإعدادات العامة',           'General Settings',             '/settings',                 'settings',     null],

            // --- Oversight and support -----------------------------------------
            ['reports',                  'التقارير والإحصائيات',       'Reports & Statistics',         '/reports',                  'bar-chart',    null],
            // Stage 80 — [D] Art. 98's twelve official registers. Top-level
            // and ungrouped beside `reports`/`audit_log`: a register is a
            // system-wide oversight record, not committee administration, so
            // it does NOT sit inside meetings_management.
            ['registers',                'السجلات الرسمية',            'Official Registers',           '/registers',                'book',         null],
            ['audit_log',                'سجل التدقيق',               'Audit Log',                    '/audit-log',                'clipboard',    null],
            ['notifications',            'الإشعارات',                 'Notifications',                '/notifications',            'bell',         null],
            ['templates',                'القوالب والنماذج',           'Templates & Forms',            '/templates',                'layout',       null],
            ['backup',                   'النسخ الاحتياطي',            'Backup',                       '/backup',                   'database',     null],
            ['user_guide',               'دليل الاستخدام',             'User Guide',                   '/guide',                    'book',         null],
        ];

        foreach ($screens as $i => [$code, $nameAr, $nameEn, $route, $icon, $group]) {
            Screen::updateOrCreate(
                ['code' => $code],
                [
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'route' => $route,
                    'icon' => $icon,
                    'group' => $group,
                    // Array position drives sidebar order (1-based). Inserting
                    // the 7 new Committee rows renumbers everything after
                    // them — cosmetic only, since `orderBy('sort_order')` is
                    // the only thing that reads this.
                    'sort_order' => $i + 1,
                    'is_active' => true,
                ],
            );
        }
    }
}
