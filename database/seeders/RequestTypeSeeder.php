<?php

namespace Database\Seeders;

use App\Models\RequestType;
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
 *
 * default_has_financial_impact (Stage 47) is true only for the two types that
 * unambiguously match the stage's own example list ("promotion, settlement,
 * allowance, back-pay, grade change") against the types that actually exist
 * today — PROM and ALLW. Settlement/back-pay/grade-change have no dedicated
 * RequestType row yet (Stage 53's request-type catalogue is the place to add
 * one, not a guess made here); every other type defaults false and is still
 * overridable per request.
 */
class RequestTypeSeeder extends Seeder
{
    public function run(): void
    {
        // [code, Arabic name, English name, SLA in days, has financial impact by default]
        $types = [
            ['PROM', 'ترقية',          'Promotion',       15, true],
            ['LEAV', 'إجازة',          'Leave',            7, false],
            ['ALLW', 'علاوة',          'Allowance',       10, true],
            ['SECD', 'انتداب',         'Secondment',      15, false],
            ['GRIV', 'تظلم',           'Grievance',       20, false],
            ['TRNS', 'نقل',            'Transfer',        15, false],
            ['EOSV', 'إنهاء خدمة',      'End of Service',  20, false],
        ];

        foreach ($types as [$code, $nameAr, $nameEn, $sla, $hasFinancialImpact]) {
            RequestType::updateOrCreate(
                ['code' => $code],
                [
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'default_sla_days' => $sla,
                    'decision_grade_threshold' => 10,
                    'is_active' => true,
                    'default_has_financial_impact' => $hasFinancialImpact,
                ],
            );
        }
    }
}
