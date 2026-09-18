<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Stage 70 (Track K) — the single owner of [D] Appendix 15's unified numbering.
 *
 * Appendix 15 ("الترقيم الموحد") gives one code family per artifact so that
 * "كل قرار بالمعاملة والمحضر والاجتماع" can be linked without losing the
 * sequence. All four are per-year and global: that table carries no
 * department or committee segment, which is why the request series drops the
 * department scoping the pre-Stage-70 `YYYY-DEPT-NNNNNN` scheme had.
 *
 * SOURCE CONFLICT, resolved deliberately: Appendix 15's table writes the
 * request's prefix `M-COM`, while النموذج 05 — the dedicated بطاقة القيد card
 * for this very artifact — writes `PM-COM` and works an example
 * (`PM-COM/2026/0047`). `PM-COM` wins: it is the more specific source, and
 * every other code either document issues is `PM-`-prefixed (Appendix 15's own
 * PM-MTG/PM-MIN/PM-DEC, Appendix 74's twenty PM-F.. form codes), so a lone
 * `M-` reads as a dropped letter rather than a distinction.
 *
 * Widths below are Appendix 15's own examples read as MINIMUMS, not caps: a
 * year that issues more than 99 meetings simply prints three digits rather
 * than wrapping.
 *
 * CONCURRENCY: call every method inside the same database transaction that
 * writes the row it numbers. `lockForUpdate` locks the matching prefix rows
 * and, on MySQL, the index gap after them, so two simultaneous callers cannot
 * claim the same serial.
 */
class ArtifactNumberGenerator
{
    private const REQUEST_PREFIX = 'PM-COM';

    private const MEETING_PREFIX = 'PM-MTG';

    private const MINUTES_PREFIX = 'PM-MIN';

    private const DECISION_PREFIX = 'PM-DEC';

    /**
     * Not an Appendix 15 code — [D] describes no intake receipt at all (see
     * AGENT_NOTES.md's Stage 70 plan). The prefix is this system's own, chosen
     * to look nothing like a قيد number so the pre-registration receipt the
     * employee keeps can never be mistaken for one, per Art. 15.
     */
    private const RECEIPT_PREFIX = 'PM-RCV';

    /** The رقم إشاري — granted when the receiving body registers the file. */
    public function nextRequestReference(): string
    {
        return $this->next(self::REQUEST_PREFIX, 4, 'requests', 'reference_number');
    }

    /** Art. 15 — proof the request was submitted, explicitly NOT a قيد. */
    public function nextIntakeReceipt(): string
    {
        return $this->next(self::RECEIPT_PREFIX, 6, 'requests', 'intake_receipt_number');
    }

    public function nextMeetingNumber(): string
    {
        return $this->next(self::MEETING_PREFIX, 2, 'meetings', 'meeting_number');
    }

    public function nextMinutesNumber(): string
    {
        return $this->next(self::MINUTES_PREFIX, 2, 'meeting_minutes', 'minutes_number');
    }

    /** Art. 89 — every decision inside the محضر carries its own رقم القرار. */
    public function nextDecisionNumber(): string
    {
        return $this->next(self::DECISION_PREFIX, 3, 'decisions', 'decision_number');
    }

    private function next(string $prefix, int $width, string $table, string $column): string
    {
        $series = sprintf('%s/%s/', $prefix, now()->format('Y'));

        // Ordering by length first keeps the sequence correct once a series
        // outgrows its padding width: plain string ordering would rank
        // '.../100' below '.../99'.
        $latest = DB::table($table)
            ->where($column, 'like', $series.'%')
            ->lockForUpdate()
            ->orderByRaw('LENGTH('.$column.') desc')
            ->orderByDesc($column)
            ->value($column);

        $lastSequence = $latest === null ? 0 : (int) substr($latest, strlen($series));

        return $series.str_pad((string) ($lastSequence + 1), $width, '0', STR_PAD_LEFT);
    }
}
