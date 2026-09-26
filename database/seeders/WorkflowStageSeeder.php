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
 * stageTimeliness()).
 *
 * Stage 71 — those figures now come from [D]'s own **Appendix 37** (مدد العمل
 * التشغيلية المقترحة), replacing outright the [A] §12 substitute Stage 52
 * adopted while the appendix was unavailable — which that stage's own note
 * said to do rather than layer a second interpretation on top.
 *
 * Appendix 37 measures the turnaround of WHOEVER IS HOLDING THE FILE, one row
 * per action, so each stage below takes the row naming the action performed
 * at that stage. A stage stays `null, null` when its row carries no day count
 * ("الاجتماع التالي") or when Appendix 37 names no action for it at all — the
 * honest gap Stage 52 established, never a fabricated target. The 14 rows map
 * onto these 12 stages as:
 *
 *   2  direct_manager_review     استلام الطلب من الرئيس المباشر  يوم عمل
 *   3  administrative_routing    إحالة الطلب للجهة المعنية       يومان
 *   4  receive_and_register      تجهيز الملف الإداري             3 أيام
 *   5  requirements_check        فحص المقرر                     يومان
 *   6  reviewer_review           المراجعة القانونية              3 أيام
 *   7  observations              إعداد مذكرة العرض               يومان
 *   9  receive_from_committee    إعداد المحضر بعد الاجتماع        3 أيام
 *   10 approval_by_authority     إحالة المحضر للاعتماد           يومان
 *   12 final_approval_archiving  إحالة القرار للتنفيذ / إشعار النتيجة  يوم / يومان
 *
 * `reviewer_review` keeps المراجعة القانونية even though Stage 68 built Art.
 * 21's pre-meeting legal review as a status-only substate of
 * `receive_from_committee`: that substate has no `workflow_stages` row and so
 * nowhere to carry a target, and this stage's own name ("مراجعة المقرر وفق
 * اللوائح") is still the only one that IS a regulatory-conformance review.
 *
 * The min/max pair is a range the source states, never a sum this seeder
 * computes: stage 12 is the only row using it, because Appendix 37 gives that
 * stage two counted actions (1 day and 2 days) rather than one figure.
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
            [2,  'direct_manager_review',     'مراجعة الطلب من المدير المباشر',    'Direct manager review',         null, 1, 1],
            [3,  'administrative_routing',    'إحالة الطلب لأحد المسارات الإدارية', 'Administrative routing',        null, 2, 2],
            [4,  'receive_and_register',      'الاستلام والتسجيل',                'Receive and register',          null, 3, 3],
            [5,  'requirements_check',        'فحص استيفاء المتطلبات',           'Requirements check',           'R02', 2, 2],
            // Stage 102 — stages 6, 7 and 8 are off the path: the مقرر's approve
            // at 5 lands on 9 (the committee's pending list), and no rule
            // reaches or leaves them (WorkflowTransitionSeeder). Kept, like the
            // routed_to_diwan status, because historical stage logs point at
            // them; renumbering the chain would be Stage-57-scale churn.
            [6,  'reviewer_review',           'مراجعة المقرر وفق اللوائح',        'Reviewer review',              'R02', 3, 3],
            [7,  'observations',              'إبداء الملاحظات (إن وجدت)',        'Observations (if any)',        'R02', 2, 2],
            // Stage 86 — R05 -> R09; Stage 96 — R09 -> R02. This field names
            // who the file is sitting with, and both hops into the committee
            // are مقرر اللجنة's again: [D] Appendix 6 has no أمين سر اللجنة
            // column, so R09 holds no row at this stage at all.
            [8,  'forward_to_committee',      'تحويل الطلب للجنة القائمة',     'Forward to committee',         'R02', null, null],
            // Display role only (see docblock): R09 -> R03, its pre-Stage-86
            // value, restored by Stage 96 for the same reason. The R03
            // decision-action `required_role_id` on vote/decision transitions
            // is seeded separately in WorkflowTransitionSeeder and is
            // unaffected by this indicative field.
            //
            // Stage 86 re-examined this deliberately and KEPT it, rather than
            // letting the field start meaning "who can act". Two reasons it
            // cannot mean that: three stages above are NULL precisely because
            // the actor is "whoever is this submitter's manager" or (before
            // Stage 96) one of three routing destinations, which a role FK
            // cannot express; and Stage 83's RequestResponsibilityService
            // already derives the who-can-act answer from the live outbound
            // rules, falling back to this column only for a stage with no rule
            // at all — so redefining it would give one question two answers
            // free to disagree.
            [9,  'receive_from_committee',    'استلام الطلب من اللجنة',        'Receive from committee',       'R03', 3, 3],
            [10, 'approval_by_authority',     'اعتماد (حسب الصلاحيات)',          'Approval (per permissions)',   'R05', 2, 2],
            [11, 'local_governance_ministry', 'وزارة الحكم المحلي',              'Local Governance Ministry',    'R06', null, null],
            [12, 'final_approval_archiving',  'الاعتماد النهائي والأرشفة',        'Final approval & archiving',   'R07', 1, 2],
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
