<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\WorkflowStage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the 14 lifecycle stages, in order, from intake to archiving.
 *
 * The `responsible_role_id` column set here is INDICATIVE — it tells the UI
 * who a request is normally sitting with at each stage. It is NOT used for
 * authorisation. Permission to actually move a request is decided by
 * workflow_transitions.required_role_id, seeded in Stage 14.
 *
 * Diagram-alignment redesign (see AGENT_NOTES.md, "Employee Affairs Committee
 * request" infographic) inserted three new front-half stages —
 * direct_manager_review, administrative_routing, receive_and_register —
 * ahead of what used to be stage 2. Three of those new stages have no single
 * fixed role (the responsible party is "whoever is this specific submitter's
 * manager", or one of three routing destinations), so responsible_role_id is
 * deliberately left NULL for them; a role FK cannot express that.
 *
 * Runs after RoleSeeder (looks roles up by code).
 */
class WorkflowStageSeeder extends Seeder
{
    public function run(): void
    {
        $rolesByCode = Role::all()->keyBy('code');

        // order_no is UNIQUE. A naive in-place renumber of existing rows
        // collides mid-loop (e.g. the new stage claiming order_no=5 while
        // ministry_endorsement still holds 5 from before this run). Bump
        // every existing row out of the entire 1..14 target range first, in
        // one set-based UPDATE, so the second pass below can freely write
        // final values with no row ever transiently colliding with another.
        // Safe headroom: order_no is unsignedSmallInteger (max 65535) and
        // there are only 14 rows.
        WorkflowStage::query()->update(['order_no' => DB::raw('order_no + 1000')]);

        // [order, code, Arabic name, English name, role usually holding it (or null — see docblock)]
        $stages = [
            [1,  'receive_from_municipality', 'استلام الطلب من البلدية',        'Receive from municipality',     'R01'],
            [2,  'direct_manager_review',     'مراجعة الطلب من المدير المباشر',    'Direct manager review',         null],
            [3,  'administrative_routing',    'إحالة الطلب لأحد المسارات الإدارية', 'Administrative routing',        null],
            [4,  'receive_and_register',      'الاستلام والتسجيل',                'Receive and register',          null],
            [5,  'requirements_check',        'فحص استيفاء المتطلبات',           'Requirements check',           'R02'],
            [6,  'reviewer_review',           'مراجعة المقرر وفق اللوائح',        'Reviewer review',              'R02'],
            [7,  'observations',              'إبداء الملاحظات (إن وجدت)',        'Observations (if any)',        'R02'],
            [8,  'ministry_endorsement',      'اعتماد الوزارة',                  'Ministry endorsement',         'R05'],
            [9,  'forward_to_committee',      'تحويل الطلب للجنة القائمة',     'Forward to committee',         'R05'],
            // Display role only (see docblock): R03 -> R09. The R03
            // decision-action `required_role_id` on vote/decision transitions
            // is seeded separately in WorkflowTransitionSeeder and is
            // unaffected by this indicative field.
            [10, 'receive_from_committee',    'استلام الطلب من اللجنة',        'Receive from committee',       'R09'],
            [11, 'approval_by_authority',     'اعتماد (حسب الصلاحيات)',          'Approval (per permissions)',   'R05'],
            [12, 'local_governance_ministry', 'وزارة الحكم المحلي',              'Local Governance Ministry',    'R06'],
            [13, 'competent_authority',       'اعتماد الجهة المختصة',            'Competent authority approval', 'R07'],
            [14, 'final_approval_archiving',  'الاعتماد النهائي والأرشفة',        'Final approval & archiving',   'R07'],
        ];

        foreach ($stages as [$order, $code, $nameAr, $nameEn, $roleCode]) {
            // Keyed on `code` so stage ids stay stable across re-seeds —
            // requests and transitions both point at them.
            WorkflowStage::updateOrCreate(
                ['code' => $code],
                [
                    'order_no' => $order,
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'responsible_role_id' => $roleCode === null ? null : $rolesByCode[$roleCode]?->id,
                ],
            );
        }
    }
}
