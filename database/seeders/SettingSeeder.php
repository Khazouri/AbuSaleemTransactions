<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Stage 60, Track J — seeds the one configurable setting this stage
 * introduces, `appeal_filing_deadline_days`, with an empty value rather than
 * a fabricated day count: no document in this folder states an actual
 * statutory deadline for filing an appeal ([D] Arts. 75–79 gives none).
 * App\Services\AppealVerificationService::deadlineMet() treats an empty or
 * absent value as "not applicable" rather than blocking every appeal on a
 * number nobody confirmed.
 *
 * firstOrCreate, not updateOrCreate: once an admin has actually configured a
 * value through the Settings screen (Stage 10), a reseed must never
 * overwrite it back to empty.
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        Setting::firstOrCreate(
            ['key' => 'appeal_filing_deadline_days'],
            ['value' => null],
        );

        // User decision 2026-10-16 — off until R08 ticks it on the Settings screen.
        Setting::firstOrCreate(
            ['key' => Setting::LEGAL_REVIEW_BYPASS],
            ['value' => '0'],
        );

        // User decision 2026-10-16 — the مقرر plus two members accept the date.
        Setting::firstOrCreate(
            ['key' => Setting::MEETING_ACCEPTANCES_REQUIRED],
            ['value' => '3'],
        );

        // User decision 2026-10-25 — off: members answer the date themselves.
        Setting::firstOrCreate(
            ['key' => Setting::MEETING_AUTO_ACCEPT],
            ['value' => '0'],
        );

        // User decision 2026-10-19 — one colour per «المهام المعلقة» source,
        // distinct hues that still read as a light tint on either theme.
        foreach ([
            'approval' => '#2e7d32',
            'candidate' => '#1565c0',
            'legal_review' => '#6a1b9a',
            'meeting_invitation' => '#00838f',
            'meeting_due' => '#0277bd',
            'completion' => '#ef6c00',
            'workflow_step' => '#558b2f',
            'overdue' => '#c62828',
            'meeting_duty' => '#00695c',
            'post_decision' => '#4527a0',
            'open_record' => '#795548',
            'appeal' => '#ad1457',
        ] as $source => $color) {
            Setting::firstOrCreate(['key' => Setting::TASK_COLOR_PREFIX.$source], ['value' => $color]);
        }
    }
}
