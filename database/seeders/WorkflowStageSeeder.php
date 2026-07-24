<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\WorkflowStage;
use Illuminate\Database\Seeder;

class WorkflowStageSeeder extends Seeder
{
    /**
     * The 11 lifecycle stages, from intake to final approval + archiving.
     *
     * responsible_role_id is the indicative owner shown in the UI; the binding
     * per-action role check is workflow_transitions.required_role_id (Stage 14).
     */
    public function run(): void
    {
        $rolesByCode = Role::all()->keyBy('code');

        $stages = [
            [1,  'receive_from_municipality', 'استلام المعاملة من البلدية',      'Receive from municipality',    'R01'],
            [2,  'requirements_check',        'فحص استيفاء المتطلبات',           'Requirements check',           'R02'],
            [3,  'reviewer_review',           'مراجعة المقرر وفق اللوائح',        'Reviewer review',              'R02'],
            [4,  'observations',              'إبداء الملاحظات (إن وجدت)',        'Observations (if any)',        'R02'],
            [5,  'ministry_endorsement',      'اعتماد الوزارة',                  'Ministry endorsement',         'R05'],
            [6,  'forward_to_committee',      'تحويل المعاملة للجنة القائمة',     'Forward to committee',         'R05'],
            [7,  'receive_from_committee',    'استلام المعاملة من اللجنة',        'Receive from committee',       'R03'],
            [8,  'approval_by_authority',     'اعتماد (حسب الصلاحيات)',          'Approval (per permissions)',   'R03'],
            [9,  'local_governance_ministry', 'وزارة الحكم المحلي',              'Local Governance Ministry',    'R06'],
            [10, 'competent_authority',       'اعتماد الجهة المختصة',            'Competent authority approval', 'R07'],
            [11, 'final_approval_archiving',  'الاعتماد النهائي والأرشفة',        'Final approval & archiving',   'R07'],
        ];

        foreach ($stages as [$order, $code, $nameAr, $nameEn, $roleCode]) {
            WorkflowStage::updateOrCreate(
                ['code' => $code],
                [
                    'order_no' => $order,
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'responsible_role_id' => $rolesByCode[$roleCode]?->id,
                ],
            );
        }
    }
}
