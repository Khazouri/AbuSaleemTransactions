<?php

namespace Database\Seeders;

use App\Models\Screen;
use Illuminate\Database\Seeder;

/**
 * Seeds the application screens, in the order they appear on the
 * screen-permissions sheet — 29 from that sheet (Stage 28 adds 7 to the
 * original 22 for the meetings-unit redesign), plus `departments` (see the
 * note beside it below) = 30 total.
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
        // [code, Arabic name, English name, Vue route, icon, group]
        // `group` is null for every ungrouped (flat, top-level) screen —
        // Stage 28 is the first to use it, on the Committee block below.
        $screens = [
            // --- Day-to-day work ---------------------------------------------
            ['dashboard',                'لوحة التحكم الرئيسية',       'Dashboard',                    '/dashboard',                'gauge',        null],
            ['transactions',             'طلبات المعاملات',            'Transaction Requests',         '/transactions',             'list',         null],
            ['transaction_intake',       'استلام المعاملة',            'Transaction Intake',           '/transactions/create',      'inbox',        null],
            ['transaction_details',      'تفاصيل المعاملة',            'Transaction Details',          '/transactions/:id',         'file-text',    null],
            ['notes_attachments',        'الملاحظات والمرفقات',        'Notes & Attachments',          '/transactions/:id/notes',   'paperclip',    null],

            // --- Committee (Stage 28: "إدارة الاجتماعات" / meetings_management) --
            // 9 slots for Track H (stages 28-37). `meetings` and `decisions`
            // already existed (Stage 20/25) and are unchanged apart from
            // gaining a `group`; the other 7 are empty navigation shells
            // until later stages give them real content.
            ['meetings_dashboard',       'لوحة قيادة الاجتماعات',      'Meetings Dashboard',           '/meetings/dashboard',       'grid',         'meetings_management'],
            ['committee_candidates',     'الطلبات المرشحة',            'Committee Candidates',         '/meetings/candidates',      'file-plus',    'meetings_management'],
            ['meetings',                 'الاجتماعات',                'Meetings',                     '/meetings',                 'users',        'meetings_management'],
            ['meeting_agenda',           'جدول الأعمال',               'Meeting Agenda',               '/meetings/agenda',          'file-text',    'meetings_management'],
            ['meeting_readiness',        'جاهزية الاجتماع',            'Meeting Readiness',            '/meetings/readiness',       'check-square', 'meetings_management'],
            ['meeting_live',             'مباشرة الاجتماع',            'Live Meeting',                 '/meetings/live',            'video',        'meetings_management'],
            ['decisions',                'القرارات والتوصيات',         'Decisions & Recommendations',  '/decisions',                'check-square', 'meetings_management'],
            ['meeting_minutes',          'المحاضر',                    'Minutes',                      '/meetings/minutes',         'book',         'meetings_management'],
            ['meeting_outputs',          'المخرجات',                   'Outputs',                      '/meetings/outputs',         'bar-chart',    'meetings_management'],

            // --- The approval chain, one screen per authority ------------------
            ['reviewer_approval',        'اعتماد المقرر',              'Reviewer Approval',            '/approvals/reviewer',       'user-check',   null],
            ['committee_head_approval',  'اعتماد رئيس اللجنة',         'Committee Head Approval',      '/approvals/committee-head', 'award',        null],
            ['admin_manager_approval',   'اعتماد مدير الإدارة',        'Admin Manager Approval',       '/approvals/admin-manager',  'briefcase',    null],
            ['ministry_approval',        'اعتماد وزارة الحكم المحلي',   'Ministry Approval',            '/approvals/ministry',       'landmark',     null],
            ['authority_approval',       'اعتماد الجهة المختصة',       'Competent Authority Approval', '/approvals/authority',      'shield',       null],
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
