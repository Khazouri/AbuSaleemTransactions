<?php

namespace Database\Seeders;

use App\Models\TransactionStatus;
use Illuminate\Database\Seeder;

/**
 * Seeds every status a transaction can hold, with the badge colour the UI
 * uses for each.
 *
 * The first nine are the normal progression. The remaining four
 * (returned / rejected / cancelled / deferred) are exception outcomes,
 * driven by the exception transitions added in Stage 16 and Stage 21 — they're
 * seeded now so those transitions have something to point at.
 */
class TransactionStatusSeeder extends Seeder
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
            ['decided',        'قرار صادر',        'Decided',        '#9f1239'], // committee has voted
            ['approved',       'معتمدة',          'Approved',       '#16a34a'],
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

            // --- Committee sub-states (Stage 29) ------------------------------
            // Status-only granularity inside the `receive_from_committee` stage,
            // written by App\Services\CommitteeStatusService — never by
            // WorkflowService, so none of these move current_stage_id.
            ['nominated_for_committee',        'مرشح للجنة',              'Nominated for Committee',        '#0ea5e9'],
            ['on_agenda',                      'مدرج بجدول الأعمال',       'On Agenda',                      '#6366f1'],
            ['under_discussion',               'قيد المناقشة',            'Under Discussion',               '#8b5cf6'],
            ['awaiting_recommendation_approval', 'بانتظار اعتماد التوصية', 'Awaiting Recommendation Approval', '#eab308'],
            ['completion_required',            'مطلوب استكمال',           'Completion Required',            '#f97316'],

            // Stage 37 reconciles these with the existing downstream path:
            // final approval enters in_execution, then the meeting outputs
            // tracker performs the status-only completed_closed move.
            ['in_execution',    'قيد التنفيذ',      'In Execution',    '#0d9488'],
            ['completed_closed', 'مكتمل ومغلق',     'Completed & Closed', '#166534'],
        ];

        foreach ($statuses as [$code, $nameAr, $nameEn, $color]) {
            TransactionStatus::updateOrCreate(
                ['code' => $code],
                ['name_ar' => $nameAr, 'name_en' => $nameEn, 'color' => $color],
            );
        }
    }
}
