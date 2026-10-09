<?php

namespace App\Services;

use App\Models\Committee;
use App\Models\Meeting;

/**
 * Stage 73 — the committee's own quorum/majority/tie rules, as transcribed
 * from its قرار التشكيل onto Appendix 65's بطاقة تعريف اللجنة.
 *
 * [D] Appendix 64 forbids the system supplying either figure for itself
 * ("ولا يجوز للدليل إنشاء نسبة نصاب أو أغلبية من تلقاء نفسه"), so **an
 * unrecorded rule is reported as absent, never substituted**: every accessor
 * here returns null rather than a default, and it is the caller's job to say
 * "غير مثبت" instead of a number. This class is the one place those
 * derivations live — readiness, the minutes compiler and the decision tally
 * all read it, so the screen that displays a quorum and the endpoint that
 * enforces it can never disagree (the `DecisionEligibility` precedent).
 *
 * The only arithmetic the system contributes is that a person is
 * indivisible. Whether the text means "at least" or "more than" its fraction
 * changes the answer (أغلبية الأعضاء of 4 is 3; نصف الأعضاء of 4 is 2), so
 * the comparator is transcribed too rather than guessed.
 */
class CommitteeVotingRules
{
    public const QUORUM_TYPES = ['count', 'fraction'];

    public const MAJORITY_TYPES = ['plurality', 'fraction'];

    /** What a majority fraction is measured against. */
    public const MAJORITY_BASES = ['votes_cast', 'present', 'members'];

    public const COMPARATORS = ['at_least', 'more_than'];

    /** Art. 87 names ترجيح صوت الرئيس; `no_decision` is "the text is silent". */
    public const TIE_BREAKS = ['chair_casting_vote', 'no_decision'];

    /** @param array<string, mixed> $rules */
    private function __construct(private readonly array $rules) {}

    public static function fromCommittee(?Committee $committee): self
    {
        if (! $committee) {
            return new self([]);
        }

        return new self(self::extract($committee));
    }

    /**
     * A meeting is judged by the rules that were in force when it was
     * convened — Stage 33's convene action freezes them onto the meeting, so
     * editing the committee card afterwards cannot rewrite the history of a
     * sitting that already happened. A meeting convened before Stage 73 (or
     * not convened at all) falls back to the committee's current card, which
     * for an untranscribed committee is honestly empty.
     */
    public static function forMeeting(Meeting $meeting): self
    {
        $snapshot = $meeting->voting_rules_snapshot;

        if (is_array($snapshot) && $snapshot !== []) {
            return new self($snapshot);
        }

        return self::fromCommittee($meeting->committee);
    }

    /** @return array<string, mixed> */
    public static function extract(Committee $committee): array
    {
        return [
            'quorum_type' => $committee->quorum_type,
            'quorum_count' => $committee->quorum_count,
            'quorum_numerator' => $committee->quorum_numerator,
            'quorum_denominator' => $committee->quorum_denominator,
            'quorum_comparator' => $committee->quorum_comparator,
            'quorum_text' => $committee->quorum_text,
            'majority_type' => $committee->majority_type,
            'majority_basis' => $committee->majority_basis,
            'majority_numerator' => $committee->majority_numerator,
            'majority_denominator' => $committee->majority_denominator,
            'majority_comparator' => $committee->majority_comparator,
            'majority_text' => $committee->majority_text,
            'tie_break' => $committee->tie_break,
            'tie_break_text' => $committee->tie_break_text,
        ];
    }

    // --- quorum ------------------------------------------------------------

    public function hasQuorumRule(): bool
    {
        return match ($this->rules['quorum_type'] ?? null) {
            'count' => ($this->rules['quorum_count'] ?? null) !== null,
            'fraction' => ($this->rules['quorum_numerator'] ?? null) !== null
                && ($this->rules['quorum_denominator'] ?? null) !== null,
            default => false,
        };
    }

    /**
     * How many members must be seated for the sitting to be validly held —
     * null when the قرار التشكيل has not been transcribed, which the caller
     * must report rather than paper over.
     */
    public function quorumRequired(int $memberCount): ?int
    {
        if (! $this->hasQuorumRule()) {
            return null;
        }

        if ($this->rules['quorum_type'] === 'count') {
            return (int) $this->rules['quorum_count'];
        }

        return $this->threshold(
            $memberCount,
            (int) $this->rules['quorum_numerator'],
            (int) $this->rules['quorum_denominator'],
            $this->rules['quorum_comparator'] ?? 'at_least',
        );
    }

    // --- majority ----------------------------------------------------------

    public function hasMajorityRule(): bool
    {
        return match ($this->rules['majority_type'] ?? null) {
            'plurality' => true,
            'fraction' => ($this->rules['majority_numerator'] ?? null) !== null
                && ($this->rules['majority_denominator'] ?? null) !== null,
            default => false,
        };
    }

    /**
     * True when the recorded rule is a real threshold rather than "whichever
     * outcome got the most votes". A committee with no card at all is treated
     * as plurality, which is the pre-Stage-73 behaviour: plurality asserts no
     * نسبة أغلبية of its own, so keeping it is not the invention Appendix 64
     * rules out — unlike a quorum number, which it is.
     */
    public function hasMajorityThreshold(): bool
    {
        return ($this->rules['majority_type'] ?? null) === 'fraction' && $this->hasMajorityRule();
    }

    public function majorityBasis(): string
    {
        return $this->rules['majority_basis'] ?? 'votes_cast';
    }

    public function majorityThreshold(int $base): ?int
    {
        if (! $this->hasMajorityThreshold()) {
            return null;
        }

        return $this->threshold(
            $base,
            (int) $this->rules['majority_numerator'],
            (int) $this->rules['majority_denominator'],
            $this->rules['majority_comparator'] ?? 'more_than',
        );
    }

    public function tieBreak(): string
    {
        return $this->rules['tie_break'] ?? 'no_decision';
    }

    // --- reporting ---------------------------------------------------------

    /**
     * The rules as recorded, for freezing onto a meeting at convene time and
     * for printing inside the محضر — Appendix 8 requires "إثبات صحة الانعقاد"
     * in the minutes, which cannot be shown without the rule it was measured
     * against. Null when nothing was ever transcribed, so an absent rule
     * reads as absent rather than as an empty one.
     *
     * @return array<string, mixed>|null
     */
    public function toArray(): ?array
    {
        if (! $this->hasQuorumRule() && ! $this->hasMajorityRule() && ($this->rules['tie_break'] ?? null) === null) {
            return null;
        }

        return $this->rules;
    }

    /**
     * The smallest whole number of people satisfying the transcribed share.
     * `at_least` rounds a fractional person up (you cannot seat 2.5 people
     * when the text asks for at least half of five); `more_than` takes the
     * next whole number strictly above the share, which is what "أغلبية"
     * means and what a plain ceil() would get wrong for an even count.
     */
    private function threshold(int $base, int $numerator, int $denominator, string $comparator): ?int
    {
        if ($denominator === 0) {
            return null;
        }

        $share = ($base * $numerator) / $denominator;

        $required = $comparator === 'more_than'
            ? (int) floor($share) + 1
            : (int) ceil($share);

        return max(0, min($required, $base));
    }
}
