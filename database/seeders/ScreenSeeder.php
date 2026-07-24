<?php

namespace Database\Seeders;

use App\Models\Screen;
use Illuminate\Database\Seeder;

class ScreenSeeder extends Seeder
{
    /**
     * The 22 system screens, in the order shown on the screen-permissions sheet.
     * `route` matches the Vue router paths used from Stage 5 onwards.
     */
    public function run(): void
    {
        $screens = [
            ['dashboard',                'لوحة التحكم الرئيسية',       'Dashboard',                    '/dashboard',                'gauge'],
            ['transactions',             'طلبات المعاملات',            'Transaction Requests',         '/transactions',             'list'],
            ['transaction_intake',       'استلام المعاملة',            'Transaction Intake',           '/transactions/create',      'inbox'],
            ['transaction_details',      'تفاصيل المعاملة',            'Transaction Details',          '/transactions/:id',         'file-text'],
            ['notes_attachments',        'الملاحظات والمرفقات',        'Notes & Attachments',          '/transactions/:id/notes',   'paperclip'],
            ['meetings',                 'الاجتماعات',                'Meetings',                     '/meetings',                 'users'],
            ['decisions',                'القرارات والتوصيات',         'Decisions & Recommendations',  '/decisions',                'check-square'],
            ['reviewer_approval',        'اعتماد المقرر',              'Reviewer Approval',            '/approvals/reviewer',       'user-check'],
            ['committee_head_approval',  'اعتماد رئيس اللجنة',         'Committee Head Approval',      '/approvals/committee-head', 'award'],
            ['admin_manager_approval',   'اعتماد مدير الإدارة',        'Admin Manager Approval',       '/approvals/admin-manager',  'briefcase'],
            ['ministry_approval',        'اعتماد وزارة الحكم المحلي',   'Ministry Approval',            '/approvals/ministry',       'landmark'],
            ['authority_approval',       'اعتماد الجهة المختصة',       'Competent Authority Approval', '/approvals/authority',      'shield'],
            ['final_approval',           'الاعتماد النهائي والأرشفة',   'Final Approval & Archiving',   '/approvals/final',          'archive'],
            ['users',                    'المستخدمون',                'Users',                        '/users',                    'user'],
            ['roles_permissions',        'الأدوار والصلاحيات',         'Roles & Permissions',          '/roles',                    'key'],
            ['settings',                 'الإعدادات العامة',           'General Settings',             '/settings',                 'settings'],
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
                    'sort_order' => $i + 1,
                    'is_active' => true,
                ],
            );
        }
    }
}
