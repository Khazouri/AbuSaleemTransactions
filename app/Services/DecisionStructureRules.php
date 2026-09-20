<?php

namespace App\Services;

/**
 * Stage 74 — what a recorded decision must contain, given the outcome the
 * vote actually produced.
 *
 * Lives in a service rather than StoreDecisionRequest because requiredness
 * depends on the tallied outcome, which is not known until
 * DecisionController has resolved it — the split that request's own docblock
 * already documents for `comment`. Both record() and
 * recordAppealDecision() read this one class, since they are the same
 * committee recording in the same محضر: the CommitteeVotingRules /
 * DecisionEligibility precedent, so an ordinary decision and an appeal
 * decision can never be held to different drafting rules by accident.
 *
 * Sources: [D] Appendix 27 (the four parts), Art. 89 (the six per-decision
 * elements), Art. 90 (قرار/توصية/رأي), Art. 34 + Appendix 29 (deferral),
 * Art. 91 + Appendix 28 (refusal reasoning).
 */
class DecisionStructureRules
{
    /**
     * Art. 90's three instruments. Deliberately the same three words as
     * RequestLegalReview::COMMITTEE_MANDATES (Appendix 22's own vocabulary),
     * minus its fourth value `study_only` — دراسة فقط is the *absence* of an
     * instrument, so it can pre-fill nothing and can never be recorded as
     * one.
     */
    public const INSTRUMENTS = ['decision', 'recommendation', 'opinion'];

    /**
     * Outcomes that dispose of the matter, as against those that keep it
     * open. Appendix 27's الوقائع and السند describe a قرار that *resolves*
     * a موضوع, so a تأجيل or a referral is not asked for the legal basis of a
     * resolution nobody has reached — those carry their own structured
     * fields instead (the five deferral columns, or REFERRING_OUTCOMES'
     * `referral_authority` below).
     */
    private const SUBSTANTIVE_OUTCOMES = [
        'approve',
        'conditional_approval',
        'reject',
        'no_jurisdiction',
        'appeal_accept',
        'appeal_partial_accept',
        'appeal_reject',
    ];

    /**
     * Art. 91's reasoned cases, restricted to the ones this system's outcome
     * vocabulary actually has: عدم الموافقة, عدم الاختصاص and رفض تظلم. The
     * article's other four (الاستبعاد، التوصية بإنهاء الخدمة، الترجيح بين عدة
     * موظفين، مخالفة رأي سابق) have no distinct outcome here and are not
     * invented into one.
     */
    private const REASONED_OUTCOMES = ['reject', 'no_jurisdiction', 'appeal_reject'];

    /**
     * Outcomes that hand the matter to somebody else, and must therefore say
     * who. [D] Art. 26 (د) and [E] 13D both require عدم الاختصاص to
     * «تحدد الجهة أو المسار الإداري المختص» — a referral that names nobody
     * tells the employee only that this committee is done with them.
     *
     * `referral_authority` has existed since Stage 50 and was nullable until
     * 2026-09-20, which is what made SUBSTANTIVE_OUTCOMES' excuse above
     * ("referrals carry their own structured fields instead") untrue for the
     * one field it named.
     *
     * `legal_opinion` is deliberately absent: Art. 26 lists asking for a legal
     * opinion among التأجيل's reasons, and it goes to the committee's own legal
     * member (R11), so it is not a referral *out* to a body that needs naming.
     */
    private const REFERRING_OUTCOMES = ['no_jurisdiction', 'refer_other_body', 'appeal_refer'];

    public function __construct(private readonly DecisionReasoningRules $reasoning) {}

    public function isSubstantive(string $outcome): bool
    {
        return in_array($outcome, self::SUBSTANTIVE_OUTCOMES, true);
    }

    public function requiresRefusalReason(string $outcome): bool
    {
        return in_array($outcome, self::REASONED_OUTCOMES, true);
    }

    public function requiresReferralAuthority(string $outcome): bool
    {
        return in_array($outcome, self::REFERRING_OUTCOMES, true);
    }

    /**
     * The first thing wrong with $payload for this outcome, or null when the
     * structure is acceptable. One message at a time, in the order [D]
     * presents the requirements, so the recorder is told what to fix rather
     * than handed a wall.
     *
     * @param  array<string, mixed>  $payload
     */
    public function firstProblem(string $outcome, array $payload): ?string
    {
        $value = fn (string $key): string => trim((string) ($payload[$key] ?? ''));

        // Art. 90 — the three instruments must be distinguished, so which one
        // was issued is recorded on every decision, not inferred.
        $instrument = $value('instrument');
        if ($instrument === '') {
            return 'يجب تحديد نوع ما أصدرته اللجنة: قرار أو توصية أو رأي.';
        }
        if (! in_array($instrument, self::INSTRUMENTS, true)) {
            return 'نوع ما أصدرته اللجنة غير صالح.';
        }

        // Art. 89 — موضوعها and منطوق النتيجة are required of every
        // per-request decision inside the محضر, unconditionally.
        if ($value('decision_subject') === '') {
            return 'يجب بيان موضوع القرار بصياغة محددة.';
        }
        if ($value('decision_operative') === '') {
            return 'يجب بيان منطوق النتيجة بصورة واضحة وقابلة للتنفيذ.';
        }

        // Appendix 27 — the منطوق must be measurable.
        if ($this->reasoning->isNothingBut(
            $value('decision_operative'),
            DecisionReasoningRules::UNMEASURABLE_OPERATIVE_PHRASES,
        )) {
            return 'لا يكفي منطوق غير قابل للقياس مثل (اتخاذ اللازم) أو (النظر في الموضوع) أو (حسب الإجراءات) دون ربطه بإجراء محدد وواضح.';
        }

        if ($this->isSubstantive($outcome)) {
            if ($value('decision_facts') === '') {
                return 'يجب إثبات وقائع الموضوع بصورة مختصرة.';
            }
            if ($value('decision_basis') === '') {
                return 'يجب بيان السند الذي استندت إليه اللجنة.';
            }
        }

        if ($this->requiresRefusalReason($outcome)) {
            if ($problem = $this->refusalProblem($payload, $value)) {
                return $problem;
            }
        }

        // Art. 26 (د) / [E] 13D — a referral must name where it is going.
        if ($this->requiresReferralAuthority($outcome) && $value('referral_authority') === '') {
            return 'يجب تحديد الجهة أو المسار الإداري المختص الذي يحال إليه الموضوع.';
        }

        if ($outcome === 'defer') {
            return $this->deferralProblem($value);
        }

        return null;
    }

    /** Appendix 28 + Art. 91. */
    private function refusalProblem(array $payload, callable $value): ?string
    {
        $code = $value('refusal_reason_code');

        if ($code === '') {
            return 'يجب تحديد سبب عدم الموافقة بصورة مهنية.';
        }
        if (! in_array($code, DecisionReasoningRules::REFUSAL_REASON_CODES, true)) {
            return 'سبب عدم الموافقة المحدد غير صالح.';
        }

        // The reasoning as a whole — الوقائع and السند are both required for
        // these outcomes (they are all substantive), so a bare phrase in
        // either is the "لا تكفي عبارات مثل…" case.
        $reasoning = trim($value('decision_facts').' '.$value('decision_basis'));

        if ($this->reasoning->isNothingBut($reasoning, DecisionReasoningRules::GENERIC_REASONING_PHRASES)) {
            return 'لا تكفي عبارات عامة مثل (لمصلحة العمل) أو (لعدم الاستحقاق) — يجب بيان السبب الحقيقي وإثباته في الملف.';
        }

        return null;
    }

    /**
     * Art. 34's five fields. The first four are required because the article
     * bullets them plainly; the fifth is optional because it alone carries
     * "إن وجدت". Together they are what makes Appendix 29's
     * "يمنع استخدام عبارة (تأجيل للمراجعة) دون بيان المطلوب" enforceable
     * rather than merely advisory.
     */
    private function deferralProblem(callable $value): ?string
    {
        if ($value('deferral_reason') === '') {
            return 'يجب إثبات سبب التأجيل.';
        }
        if ($value('deferral_required_completion') === '') {
            return 'يجب بيان المطلوب استكماله — لا يقبل التأجيل للمراجعة دون بيان المطلوب.';
        }
        if ($value('deferral_responsible_body') === '') {
            return 'يجب تحديد الجهة المسؤولة عن الاستكمال.';
        }
        if ($value('deferral_required_document') === '') {
            return 'يجب تحديد المستند أو الإفادة المطلوبة.';
        }

        return null;
    }
}
