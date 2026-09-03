<?php

namespace App\Services;

use App\Models\Appeal;
use App\Models\Setting;

/**
 * Stage 60, Track J — [A] §9 step 2's المواعيد القانونية check: whether an
 * appeal was filed within a statutory deadline of تاريخ العلم (known_at).
 *
 * No document in this folder states an actual number of days ([D] Arts.
 * 75–79 gives none), so the day count is a configurable Setting
 * (`appeal_filing_deadline_days`), seeded empty by SettingSeeder. Unlike the
 * other three admissibility checks (صفة المتظلم / القرار محل التظلم /
 * عدم التكرار), which are human judgment calls a verifier attests to, this
 * one is plain arithmetic once a deadline is actually configured — so it is
 * computed here, not submitted by the verifier.
 */
class AppealVerificationService
{
    private const SETTING_KEY = 'appeal_filing_deadline_days';

    /**
     * Null means "no deadline configured" — never blocks verification.
     * True/false means an actual day count was checked against known_at.
     */
    public function deadlineMet(Appeal $appeal): ?bool
    {
        $days = trim((string) Setting::where('key', self::SETTING_KEY)->value('value'));

        if ($days === '' || ! ctype_digit($days)) {
            return null;
        }

        $deadline = $appeal->known_at->copy()->addDays((int) $days)->endOfDay();

        return $appeal->created_at->lte($deadline);
    }
}
