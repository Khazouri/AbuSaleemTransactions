<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\WorkflowStage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the 12 lifecycle stages, in order, from intake to archiving.
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
 * Stage 57 removed two stages that had no counterpart in [A]/[D]/[E]'s
 * standard — `ministry_endorsement` (pre-committee) and `competent_authority`
 * (a fourth post-committee approval tier) — see AGENT_NOTES.md. 14 stages
 * became 12; every code below order 8 is unchanged, everything from
 * `forward_to_committee` onward shifted down.
 *
 * Runs after RoleSeeder (looks roles up by code).
 *
 * Stage 52 — `target_days_min`/`target_days_max` are a non-binding, soft-SLA
 * target duration per stage (never blocking — see App\Models\Request::
 * stageTimeliness()). Sourced from [A] §12's 10-step timeframe list, which
 * `docs/employee-committee-lifecycle/official-process-summary.md` cross-
 * references as the same figures [D]'s own appendix uses. [A]'s 10 steps and
 * this table's 12 stages don't share granularity, so the mapping below is a
 * judgment call — recorded in full in AGENT_NOTES.md's Stage 52 plan entry,
 * not re-derived here. A stage left `null, null` genuinely has no sourced
 * target (either it postdates [A]'s text — the diagram-alignment redesign's
 * stages 2–3 — or [A] gives it no day count at all, e.g. "أول اجتماع متاح").
 */
class WorkflowStageSeeder extends Seeder
{
    public function run(): void
    {
        $rolesByCode = Role::all()->keyBy('code');

        // Stage 57 — delete first: workflow_transitions.from_stage_id/
        // to_stage_id are cascadeOnDelete on workflow_stages, so any row
        // still pointing at either of these two (normal or exception) is
        // removed automatically. WorkflowTransitionSeeder's own rewrite has
        // nothing stale left to clean up on top of this.
        WorkflowStage::query()->whereIn('code', ['ministry_endorsement', 'competent_authority'])->delete();

        // order_no is UNIQUE. A naive in-place renumber of existing rows
        // collides mid-loop (e.g. a stage claiming order_no=8 while another
        // still holds 8 from before this run). Bump every existing row out
        // of the entire 1..12 target range first, in one set-based UPDATE,
        // so the second pass below can freely write final values with no row
        // ever transiently colliding with another. Safe headroom: order_no
        // is unsignedSmallInteger (max 65535) and there are only 12 rows.
        WorkflowStage::query()->update(['order_no' => DB::raw('order_no + 1000')]);

        // [order, code, Arabic name, English name, role usually holding it (or null — see docblock), target_days_min, target_days_max]
        $stages = [
            [1,  'receive_from_municipality', 'استلام الطلب من البلدية',        'Receive from municipality',     'R01', null, null],
            [2,  'direct_manager_review',     'مراجعة الطلب من المدير المباشر',    'Direct manager review',         null, null, null],
            [3,  'administrative_routing',    'إحالة الطلب لأحد المسارات الإدارية', 'Administrative routing',        null, null, null],
            [4,  'receive_and_register',      'الاستلام والتسجيل',                'Receive and register',          null, 1, 1],
            [5,  'requirements_check',        'فحص استيفاء المتطلبات',           'Requirements check',           'R02', 3, 3],
            [6,  'reviewer_review',           'مراجعة المقرر وفق اللوائح',        'Reviewer review',              'R02', 5, 5],
            [7,  'observations',              'إبداء الملاحظات (إن وجدت)',        'Observations (if any)',        'R02', 5, 10],
            [8,  'forward_to_committee',      'تحويل الطلب للجنة القائمة',     'Forward to committee',         'R05', null, null],
            // Display role only (see docblock): R03 -> R09. The R03
            // decision-action `required_role_id` on vote/decision transitions
            // is seeded separately in WorkflowTransitionSeeder and is
            // unaffected by this indicative field.
            [9,  'receive_from_committee',    'استلام الطلب من اللجنة',        'Receive from committee',       'R09', 3, 3],
            [10, 'approval_by_authority',     'اعتماد (حسب الصلاحيات)',          'Approval (per permissions)',   'R05', 5, 5],
            [11, 'local_governance_ministry', 'وزارة الحكم المحلي',              'Local Governance Ministry',    'R06', null, null],
            [12, 'final_approval_archiving',  'الاعتماد النهائي والأرشفة',        'Final approval & archiving',   'R07', 5, 5],
        ];

        foreach ($stages as [$order, $code, $nameAr, $nameEn, $roleCode, $targetMin, $targetMax]) {
            // Keyed on `code` so stage ids stay stable across re-seeds —
            // requests and transitions both point at them.
            WorkflowStage::updateOrCreate(
                ['code' => $code],
                [
                    'order_no' => $order,
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'responsible_role_id' => $roleCode === null ? null : $rolesByCode[$roleCode]?->id,
                    'target_days_min' => $targetMin,
                    'target_days_max' => $targetMax,
                ],
            );
        }
    }
}
