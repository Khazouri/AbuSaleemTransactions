<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Stage 17 — run after midnight so the due date remains valid all day.
Schedule::command('requests:flag-overdue')->dailyAt('00:05')->withoutOverlapping();

// Stage 71 — [D] Appendix 38's delay ladder. Runs after the hard-SLA sweep
// above so the two never write the same request in the same minute, and early
// enough that a rung raised overnight is waiting when its owner signs in.
Schedule::command('requests:escalate-delays')->dailyAt('00:20')->withoutOverlapping();

// Stage 26 — nightly snapshot, late enough that the overdue sweep above has
// already settled, so the backup captures the day's flags rather than racing
// them. withoutOverlapping matters more here than above: two mysqldumps of the
// same database at once is pure contention.
Schedule::command('backup:run')->dailyAt('01:30')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
