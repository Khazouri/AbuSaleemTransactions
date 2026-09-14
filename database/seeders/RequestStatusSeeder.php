<?php

namespace Database\Seeders;

use App\Models\RequestStatus;
use Illuminate\Database\Seeder;

/**
 * Seeds every status a request can hold, with the badge colour the UI
 * uses for each.
 *
 * The array is grouped by kind — the normal progression first, then the
 * exception outcomes (returned / rejected / cancelled / deferred, driven by
 * the transitions added in Stage 16 and Stage 21), then the committee
 * sub-states and the later Track J/K additions. No count is given on purpose:
 * this vocabulary has grown from 13 rows to 41 across Stages 29/54b/69/77/78,
 * and every figure a comment here has ever stated went stale within two
 * stages. Read the `$statuses` array.
 *
 * Stage 69 (Track K) made this vocabulary Art. 38's own twenty-code
 * dictionary: it added codes 13, 15, 16 and 19, which had no status of
 * their own, and retired `decided`/`approved` to legacy. Before that,
 * Stage 54b reconciled the then-28-row vocabulary against [D] Art. 38's
 * canonical 20-code dictionary — see
 * docs/employee-committee-lifecycle/gap-analysis.md §4 (git-ignored,
 * local-only) for the per-status mapping and the rename/merge/keep decision
 * behind each one before assuming a status name or its reuse across
 * multiple transitions is an oversight.
 */
class RequestStatusSeeder extends Seeder
{
    public function run(): void
    {
        // [code, Arabic name, English name, badge colour]
        $statuses = [
            // --- Normal progression ------------------------------------------
            ['new',            'جديد',            'New',            '#10b981'],
            ['in_review',      'قيد المراجعة',     'In Review',      '#f59e0b'],
            ['incomplete',     'ناقص',            'Incomplete',     '#b45309'], // missing documents
            ['ready',          'جاهزة',           'Ready',          '#14b8a6'], // fit for the agenda
            ['in_meeting',     'في الاجتماع',      'In Meeting',     '#7c3aed'],
            // Stage 69 (Track K) — both of these are now LEGACY: no seeded
            // transition sets either any more, and both stay recognised
            // wherever they are read so old request_status_history rows keep
            // making sense (the same treatment `archived` already gets).
            //
            // Art. 38 keeps code 12 (موافق عليها من اللجنة) and code 15
            // (بانتظار اعتماد البلدية) apart because [D] treats الإحالة إلى
            // السلطة المحلية as its own act — but DecisionController::record()
            // performs the committee's approve decision and that referral
            // atomically, so no state exists between them. The arrival status
            // at approval_by_authority is therefore the more specific and more
            // current of the two (15, below); the `decisions` row itself is the
            // permanent record of 12, which is where Art. 89 puts it anyway.
            //
            // `approved` was read as spanning codes 15–16 by Stage 54b, but
            // Stage 57 deleted the `competent_authority` stage and with it the
            // second of its two call sites — leaving it meaning only Art. 31's
            // code 16, which now has a status that says so.
            ['decided',        'قرار صادر',        'Decided',        '#9f1239'], // legacy — Art. 38 code 12
            ['approved',       'معتمدة',          'Approved',       '#16a34a'], // legacy — superseded by 15/16
            ['final_approved', 'معتمدة نهائياً',   'Final Approved', '#065f46'],
            ['archived',       'مؤرشفة',          'Archived',       '#d97706'], // closed, read-only

            // --- Exception outcomes (Stage 16) -------------------------------
            ['returned',       'مرجعة',           'Returned',       '#ea580c'], // sent back a stage
            ['rejected',       'مرفوضة',          'Rejected',       '#dc2626'],
            ['cancelled',      'ملغاة',           'Cancelled',      '#6b7280'],

            // --- Committee voting outcome (Stage 21) -------------------------
            ['deferred',       'مؤجلة',           'Deferred',       '#64748b'], // sent back to committee for the next meeting

            // --- Richer committee decision outcomes (Stage 35) ---------------
            ['approved_with_conditions', 'اعتماد مشروط',     'Approved with Conditions', '#0891b2'], // like approve, but forwards a condition text
            ['legal_opinion_requested',  'طلب رأي قانوني',   'Legal Opinion Requested',  '#a21caf'], // self-loop at stage 7, pending legal review
            ['referred_to_other_body',   'أحيلت لجهة أخرى',  'Referred to Other Body',  '#57534e'], // self-loop at stage 7, outside this committee's remit

            // --- Richer committee decision outcomes (Stage 49) ---------------
            // Distinct from `referred_to_other_body` above: that one asks
            // another body for input ([D] Art. 26's deferral reason), this
            // one declares the matter outside the committee's jurisdiction
            // entirely ([D] status 14 "عدم اختصاص").
            ['outside_jurisdiction',     'عدم اختصاص',       'Outside Jurisdiction',    '#78350f'],

            // --- Committee sub-states (Stage 29) ------------------------------
            // Status-only granularity inside the `receive_from_committee` stage,
            // written by App\Services\CommitteeStatusService — never by
            // WorkflowService, so none of these move current_stage_id.
            ['nominated_for_committee',        'مرشح للجنة',              'Nominated for Committee',        '#0ea5e9'],
            ['on_agenda',                      'مدرج بجدول الأعمال',       'On Agenda',                      '#6366f1'],
            ['under_discussion',               'قيد المناقشة',            'Under Discussion',               '#8b5cf6'],
            ['awaiting_recommendation_approval', 'بانتظار اعتماد التوصية', 'Awaiting Recommendation Approval', '#eab308'],
            ['completion_required',            'مطلوب استكمال',           'Completion Required',            '#f97316'],

            // Stage 68 (Track K) — [D] Art. 38's code 07 (تحت المراجعة القانونية
            // "لدى العضو القانوني"), the one Art. 38 code with no counterpart
            // before this stage. Also status-only, written by the same
            // CommitteeStatusService as the five above: Art. 21's review is
            // pre-agenda preparation inside `receive_from_committee`, not a
            // workflow stage of its own.
            ['under_legal_review',             'تحت المراجعة القانونية',   'Under Legal Review',             '#0f766e'],

            // Stage 37 reconciles these with the existing downstream path:
            // final approval enters in_execution, then the meeting outputs
            // tracker performs the status-only completed_closed move.
            ['in_execution',    'قيد التنفيذ',      'In Execution',    '#0d9488'],
            ['completed_closed', 'مكتمل ومغلق',     'Completed & Closed', '#166534'],

            // --- Diagram-alignment redesign: 3-way administrative routing
            // and registration (see AGENT_NOTES.md) ---------------------------
            // Which route a request took is recorded as its status, since
            // that is also how WorkflowTransitionSeeder's `register` rows
            // enforce that only the matching receiving role can register it
            // (required_status_id) — see actorMayUse() in WorkflowService.
            ['routed_to_hr',                   'موجّه إلى الموارد البشرية',        'Routed to HR',                    '#2563eb'],
            ['routed_to_diwan',                'موجّه إلى وكيل الديوان',           'Routed to Diwan Deputy',          '#4f46e5'],
            ['routed_to_committee_secretary',  'موجّه إلى أمين سر اللجنة',         'Routed to Committee Secretary',   '#7c3aed'],
            ['registered',                     'تم التسجيل',                     'Registered',                      '#0891b2'],

            // --- Track J, Stage 64: appeal outcome execution -----------------
            // Written only by App\Services\AppealOutcomeExecutor / WorkflowService
            // ::reopenAtStage() — never by an ordinary workflow_transitions row.
            // Both "decision_*" statuses are terminal (WorkflowService::
            // hasTerminalStatus, CommitteeStatusService's own copy): the appeal
            // body's own decision IS the final word on the matter once it
            // accepts or partially accepts the appeal, so no further ordinary
            // processing follows. `reopened_by_appeal` is deliberately NOT
            // terminal — the whole point of إعادة الإجراءات is that the request
            // re-enters ordinary processing at the stage the defect occurred.
            ['decision_withdrawn', 'قرار مسحوب بموجب تظلم',  'Decision Withdrawn (Appeal)', '#9f1239'],
            ['decision_amended',   'قرار معدَّل بموجب تظلم', 'Decision Amended (Appeal)',   '#be185d'],
            ['reopened_by_appeal', 'أعيد فتحه بموجب تظلم',   'Reopened via Appeal',         '#0ea5e9'],

            // --- Track J, Stage 66: the general reopen mechanism -------------
            // Distinct from `reopened_by_appeal` above, which is only ever
            // set by AppealOutcomeExecutor's `appeal_redo` case. This one is
            // set by App\Http\Controllers\Api\RequestController::reopen(),
            // reachable even without any appeal at all — [D] Arts. 34–37's
            // إعادة العرض rule (re-presenting a concluded matter for a new
            // document/material-error/legal-status reason) is a broader
            // mechanism than appeal-driven redo. Not terminal, for the same
            // reason reopened_by_appeal isn't: ordinary processing resumes.
            ['reopened_for_representation', 'أعيد فتحه لإعادة العرض', 'Reopened for Re-presentation', '#0284c7'],

            // --- Track K, Stage 69: the four codes Art. 38 has and this
            // vocabulary did not ---------------------------------------------
            // Art. 31 names 16 verbatim ("وفي هذه الحالة تصبح حالة المعاملة:
            // بانتظار الاعتماد المركزي"). Appendix 5 keeps 19 and 20 apart in
            // so many words ("منفذة … لكنها لا تصبح مغلقة إلا بعد التحقق من
            // اكتمال التوثيق"), which is why MeetingOutputService now has two
            // status-only actions instead of one. And 13 is the committee's own
            // non-approval, until this stage indistinguishable in the data from
            // a plain administrative withdrawal because DecisionController
            // routed the `reject` outcome through the generic `cancel`
            // self-loop — gap-analysis §4's one explicitly-open finding.
            ['awaiting_municipal_approval', 'بانتظار اعتماد البلدية',   'Awaiting Municipal Approval', '#0369a1'],
            ['awaiting_central_approval',   'بانتظار الاعتماد المركزي', 'Awaiting Central Approval',   '#1d4ed8'],
            ['not_approved',                'غير موافق عليها',          'Not Approved',                '#b91c1c'],
            ['executed',                    'منفذة',                    'Executed',                    '#15803d'],

            // --- Track K, Stage 77: returned from the approving body -------
            // [D] Art. 38 has no code for this state — its twenty stop at
            // بانتظار الاعتماد and معتمدة نهائيًا, with nothing between them for
            // a file the approving body sends back. Appendix 48 names it
            // verbatim as its seventh closure-refusal condition ("أعيدت من جهة
            // الاعتماد"), so this is sourced rather than invented, in the same
            // category as the routing/committee sub-states above that Art. 38
            // also does not itemise.
            //
            // Deliberately NOT terminal: Art. 94 requires a re-processing
            // action, so the file is very much still open work. Written only
            // by App\Services\ApprovalReturnService::record(), and cleared by
            // its resolve() back to the awaiting status or forward to Art.
            // 78's إعادة عرض.
            ['returned_by_approving_body', 'أعيدت من جهة الاعتماد', 'Returned by Approving Body', '#c2410c'],

            // --- Track K, Stage 78: Art. 105's procedural suspension --------
            // "إذا ظهر قبل الاعتماد أو التنفيذ أن معلومة جوهرية غير صحيحة أو أن
            // مستندًا أساسيًا محل شك: يوقف التنفيذ فورًا من الناحية الإجرائية
            // ويحال الموضوع للمراجعة القانونية والجهة المختصة قبل ترتيب أثر
            // جديد عليه." Art. 38 has no code for this either — like Stage
            // 77's status above it is named by its own article rather than by
            // the dictionary, and for the same reason: [D] enumerates the
            // twenty ordinary states, not every procedural hold it also
            // mandates elsewhere.
            //
            // Deliberately NOT terminal — the whole point of Art. 105 is that
            // the matter is reviewed and then resumes or goes back to the
            // committee. Written only by App\Services\RequestSuspensionService.
            ['execution_suspended', 'موقوفة لمراجعة قانونية', 'Suspended for Legal Review', '#7f1d1d'],
        ];

        foreach ($statuses as [$code, $nameAr, $nameEn, $color]) {
            RequestStatus::updateOrCreate(
                ['code' => $code],
                ['name_ar' => $nameAr, 'name_en' => $nameEn, 'color' => $color],
            );
        }
    }
}
