<?php

namespace Database\Seeders;

use App\Models\TransactionStatus;
use Illuminate\Database\Seeder;

class TransactionStatusSeeder extends Seeder
{
    /**
     * Transaction lifecycle states, including the exception-path states
     * (returned / rejected / cancelled) used by Stage 16.
     */
    public function run(): void
    {
        $statuses = [
            ['new',            'جديد',            'New',            '#10b981'],
            ['in_review',      'قيد المراجعة',     'In Review',      '#f59e0b'],
            ['incomplete',     'ناقص',            'Incomplete',     '#b45309'],
            ['ready',          'جاهزة',           'Ready',          '#14b8a6'],
            ['in_meeting',     'في الاجتماع',      'In Meeting',     '#7c3aed'],
            ['decided',        'قرار صادر',        'Decided',        '#9f1239'],
            ['approved',       'معتمدة',          'Approved',       '#16a34a'],
            ['final_approved', 'معتمدة نهائياً',   'Final Approved', '#065f46'],
            ['archived',       'مؤرشفة',          'Archived',       '#d97706'],
            ['returned',       'مرجعة',           'Returned',       '#ea580c'],
            ['rejected',       'مرفوضة',          'Rejected',       '#dc2626'],
            ['cancelled',      'ملغاة',           'Cancelled',      '#6b7280'],
        ];

        foreach ($statuses as [$code, $nameAr, $nameEn, $color]) {
            TransactionStatus::updateOrCreate(
                ['code' => $code],
                ['name_ar' => $nameAr, 'name_en' => $nameEn, 'color' => $color],
            );
        }
    }
}
