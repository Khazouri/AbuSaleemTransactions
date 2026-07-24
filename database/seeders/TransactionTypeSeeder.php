<?php

namespace Database\Seeders;

use App\Models\TransactionType;
use Illuminate\Database\Seeder;

/**
 * Seeds the staff-affairs request types handled by the committee.
 *
 * Each type sets its own SLA — a leave request is expected to clear in a week,
 * a grievance gets twenty days. Stage 17 turns that into a due_date and flags
 * anything that overruns.
 *
 * decision_grade_threshold is 10 for every type: per the workflow spec, a
 * decision of grade 10 or above must be escalated to وزارة الحكم المحلي
 * (stage 9) rather than being settled inside the municipality. It is a
 * per-type column so a category could later be given a different bar without
 * touching code. Stage 18 enforces it.
 */
class TransactionTypeSeeder extends Seeder
{
    public function run(): void
    {
        // [code, Arabic name, English name, SLA in days]
        $types = [
            ['PROM', 'ترقية',          'Promotion',       15],
            ['LEAV', 'إجازة',          'Leave',            7],
            ['ALLW', 'علاوة',          'Allowance',       10],
            ['SECD', 'انتداب',         'Secondment',      15],
            ['GRIV', 'تظلم',           'Grievance',       20],
            ['TRNS', 'نقل',            'Transfer',        15],
            ['EOSV', 'إنهاء خدمة',      'End of Service',  20],
        ];

        foreach ($types as [$code, $nameAr, $nameEn, $sla]) {
            TransactionType::updateOrCreate(
                ['code' => $code],
                [
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'default_sla_days' => $sla,
                    'decision_grade_threshold' => 10,
                    'is_active' => true,
                ],
            );
        }
    }
}
