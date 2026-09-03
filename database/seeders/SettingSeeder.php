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
    }
}
