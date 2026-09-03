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
 * Status codes read as "the milestone just reached" (same convention as
 * RequestStatus/CommitteeStatusService): App\Http\Controllers\Api\
 * AppealController::verify() (Stage 60) moves `submitted` straight to
 * `formal_verification` on a pass — there is no separate "currently being
 * verified" row.
 *
 * Stage 60 also adds a 7th row, `rejected` — a terminal BRANCH outcome (a
 * failed admissibility check), not an 8th step in the [A] §9 sequence. It is
 * deliberately its own status rather than reusing `notified_closed`: that
 * status is Stage 65's own closure mechanism (an 8-field closure record + a
 * notification event), and reusing its name here would misrepresent an
 * unbuilt mechanism as having fired for what is really an early
 * administrative rejection.
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
            // Stage 60 — a formal-verification failure branch, not a 7th
            // step in the happy-path sequence above (see the class docblock).
            [7, 'rejected',               'مرفوض شكلياً',                 'Formally Rejected',         '#b91c1c'],
        ];

        foreach ($statuses as [$orderNo, $code, $nameAr, $nameEn, $color]) {
            AppealStatus::updateOrCreate(
                ['code' => $code],
                ['order_no' => $orderNo, 'name_ar' => $nameAr, 'name_en' => $nameEn, 'color' => $color],
            );
        }
    }
}
