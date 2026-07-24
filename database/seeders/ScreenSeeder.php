<?php

namespace Database\Seeders;

use App\Models\Screen;
use Illuminate\Database\Seeder;

/**
 * Seeds the 22 application screens, in the order they appear on the
 * screen-permissions sheet.
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
        // [code, Arabic name, English name, Vue route, icon]
        $screens = [
            // --- Day-to-day work ---------------------------------------------
            ['dashboard',                'لوحة التحكم الرئيسية',       'Dashboard',                    '/dashboard',                'gauge'],
            ['transactions',             'طلبات المعاملات',            'Transaction Requests',         '/transactions',             'list'],
            ['transaction_intake',       'استلام المعاملة',            'Transaction Intake',           '/transactions/create',      'inbox'],
            ['transaction_details',      'تفاصيل المعاملة',            'Transaction Details',          '/transactions/:id',         'file-text'],
            ['notes_attachments',        'الملاحظات والمرفقات',        'Notes & Attachments',          '/transactions/:id/notes',   'paperclip'],

            // --- Committee ----------------------------------------------------
            ['meetings',                 'الاجتماعات',                'Meetings',                     '/meetings',                 'users'],
            ['decisions',                'القرارات والتوصيات',         'Decisions & Recommendations',  '/decisions',                'check-square'],

            // --- The approval chain, one screen per authority ------------------
            ['reviewer_approval',        'اعتماد المقرر',              'Reviewer Approval',            '/approvals/reviewer',       'user-check'],
            ['committee_head_approval',  'اعتماد رئيس اللجنة',         'Committee Head Approval',      '/approvals/committee-head', 'award'],
            ['admin_manager_approval',   'اعتماد مدير الإدارة',        'Admin Manager Approval',       '/approvals/admin-manager',  'briefcase'],
            ['ministry_approval',        'اعتماد وزارة الحكم المحلي',   'Ministry Approval',            '/approvals/ministry',       'landmark'],
            ['authority_approval',       'اعتماد الجهة المختصة',       'Competent Authority Approval', '/approvals/authority',      'shield'],
            ['final_approval',           'الاعتماد النهائي والأرشفة',   'Final Approval & Archiving',   '/approvals/final',          'archive'],

            // --- Administration ------------------------------------------------
            ['users',                    'المستخدمون',                'Users',                        '/users',                    'user'],
            ['roles_permissions',        'الأدوار والصلاحيات',         'Roles & Permissions',          '/roles',                    'key'],
            ['settings',                 'الإعدادات العامة',           'General Settings',             '/settings',                 'settings'],

            // --- Oversight and support -----------------------------------------
            ['reports',                  'التقارير والإحصائيات',       'Reports & Statistics',         '/reports',                  'bar-chart'],
            ['audit_log',                'سجل التدقيق',               'Audit Log',                    '/audit-log',                'clipboard'],
            ['notifications',            'الإشعارات',                 'Notifications',                '/notifications',            'bell'],
            ['templates',                'القوالب والنماذج',           'Templates & Forms',            '/templates',                'layout'],
            ['backup',                   'النسخ الاحتياطي',            'Backup',                       '/backup',                   'database'],
            ['user_guide',               'دليل الاستخدام',             'User Guide',                   '/guide',                    'book'],
        ];

        foreach ($screens as $i => [$code, $nameAr, $nameEn, $route, $icon]) {
            Screen::updateOrCreate(
                ['code' => $code],
                [
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'route' => $route,
                    'icon' => $icon,
                    // Array position drives sidebar order (1-based).
                    'sort_order' => $i + 1,
                    'is_active' => true,
                ],
            );
        }
    }
}
