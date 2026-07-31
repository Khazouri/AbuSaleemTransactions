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
        ];

        foreach ($statuses as [$code, $nameAr, $nameEn, $color]) {
            TransactionStatus::updateOrCreate(
                ['code' => $code],
                ['name_ar' => $nameAr, 'name_en' => $nameEn, 'color' => $color],
            );
        }
    }
}
