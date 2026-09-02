<?php

namespace Database\Seeders;

use App\Models\AppealStatus;
use Illuminate\Database\Seeder;

/**
 * Stage 58 — the six-step appeal status machine, taken verbatim from [A]
 * §9's own numbered sequence (تقديم → التحقق الشكلي → جمع الملف الأصلي →
 * المراجعة القانونية → العرض على اللجنة أو الجهة المختصة → التبليغ
 * والإغلاق). Each step lines up with a later Track J stage (59/60/61/62/
 * 63/65) — see STAGE_PLAN.md.
 *
 * No workflow logic reads these yet (Stage 58 is schema-only); an appeal is
 * created at `submitted` and nothing advances it further until Stage 59+.
 */
class AppealStatusSeeder extends Seeder
{
    public function run(): void
    {
        // [order_no, code, Arabic name, English name, badge colour]
        $statuses = [
            [1, 'submitted',              'تقديم التظلم',                 'Submitted',                 '#0ea5e9'],
            [2, 'formal_verification',    'التحقق الشكلي',               'Formal Verification',       '#f59e0b'],
            [3, 'file_assembly',          'جمع الملف الأصلي',             'Original File Assembly',    '#8b5cf6'],
            [4, 'legal_review',           'المراجعة القانونية',           'Legal Review',              '#a21caf'],
            [5, 'committee_presentation', 'العرض على اللجنة أو الجهة المختصة', 'Committee/Authority Presentation', '#7c3aed'],
            [6, 'notified_closed',        'التبليغ والإغلاق',             'Notified & Closed',         '#166534'],
        ];

        foreach ($statuses as [$orderNo, $code, $nameAr, $nameEn, $color]) {
            AppealStatus::updateOrCreate(
                ['code' => $code],
                ['order_no' => $orderNo, 'name_ar' => $nameAr, 'name_en' => $nameEn, 'color' => $color],
            );
        }
    }
}
