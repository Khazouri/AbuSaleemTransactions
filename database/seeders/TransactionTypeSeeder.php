<?php

namespace Database\Seeders;

use App\Models\TransactionType;
use Illuminate\Database\Seeder;

class TransactionTypeSeeder extends Seeder
{
    /**
     * Staff-affairs transaction types.
     *
     * decision_grade_threshold = 10 across the board: per the workflow spec, a
     * decision of grade 10 or above must be escalated to the Ministry of Local
     * Governance (stage 9). Enforced by the approval chain in Stage 18.
     */
    public function run(): void
    {
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
