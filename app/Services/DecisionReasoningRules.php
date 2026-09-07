<?php

namespace App\Services;

/**
 * Stage 74 — [D] Appendix 28's professional refusal reasons, and the phrases
 * Appendix 27 / Art. 91 / Appendix 28 say are not enough on their own.
 *
 * Kept apart from DecisionStructureRules (which decides *which* fields an
 * outcome needs) because this is the vocabulary itself: the codes are read by
 * the API, the register and the frontend's own picker, so they live in one
 * place rather than being restated per consumer — the ReopenReasonCatalog
 * precedent from Stage 66.
 */
class DecisionReasoningRules
{
    /**
     * Appendix 28's own seven reasons, transcribed in the order the source
     * lists them, plus `other`.
     *
     * `other` is here because the appendix introduces its list with "مثل" —
     * these are examples of a professional reason, not a closed set, and an
     * exhaustive enum would shut a list the source deliberately leaves open.
     * It is not a loophole: every outcome that requires a code is also a
     * substantive one, so DecisionStructureRules already requires الوقائع
     * and السند alongside it, and the phrase checks below still apply.
     */
    public const REFUSAL_REASON_CODES = [
        'period_condition_unmet',        // عدم استيفاء شرط المدة
        'position_unavailable',          // عدم توفر الوظيفة المطلوبة
        'legal_text_inapplicable',       // عدم انطباق النص القانوني
        'essential_condition_missing',   // نقص شرط أساسي لا يمكن استكماله
        'outside_jurisdiction',          // عدم اختصاص اللجنة
        'document_invalid',              // عدم صحة المستند المؤثر
        'legal_impediment',              // وجود مانع قانوني
        'other',
    ];

    /**
     * Appendix 27 — "ولا تستخدم عبارات غير قابلة للقياس مثل: (اتخاذ اللازم) ·
     * (النظر في الموضوع) · (حسب الإجراءات) — إلا إذا كانت مرتبطة بإجراء محدد
     * وواضح." Checked against the المنطوق only, which is what that appendix's
     * ban is about, and the "إلا إذا" is exactly why the test below is one of
     * sufficiency rather than prohibition.
     */
    public const UNMEASURABLE_OPERATIVE_PHRASES = [
        'اتخاذ اللازم',
        'النظر في الموضوع',
        'حسب الإجراءات',
    ];

    /**
     * Art. 91 — "ولا تكفي عبارات مثل «لمصلحة العمل» أو «لعدم الاستحقاق»…" —
     * plus Appendix 28's "لا يكتب: (لم توافق اللجنة) فقط", and Appendix 59's
     * fourth formula repeating "وعدم الاكتفاء بعبارة (عدم الاستحقاق)".
     * Checked against the reasoning (الوقائع + السند) of the outcomes Art. 91
     * names.
     */
    public const GENERIC_REASONING_PHRASES = [
        'لمصلحة العمل',
        'لعدم الاستحقاق',
        'عدم الاستحقاق',
        'لم توافق اللجنة',
    ];

    /**
     * Is $text nothing but the listed phrases?
     *
     * Every source states an *insufficiency* rule, never an outright ban —
     * "لا تكفي" (Art. 91), "عدم الاكتفاء بعبارة" (Appendix 59), "فقط"
     * (Appendix 28), "إلا إذا كانت مرتبطة بإجراء محدد وواضح" (Appendix 27) —
     * so the phrase accompanied by specifics is acceptable and only the
     * phrase standing alone is not. The test is therefore "remove every
     * listed phrase; does anything remain?", with no minimum word count and
     * no similarity scoring: a threshold would be an invented number of
     * exactly the kind Stage 73 spent its whole scope deleting.
     *
     * @param  list<string>  $phrases
     */
    public function isNothingBut(?string $text, array $phrases): bool
    {
        $text = trim((string) $text);

        if ($text === '') {
            return false; // Emptiness is a different failure; presence is checked separately.
        }

        $remainder = $text;

        foreach ($phrases as $phrase) {
            $remainder = str_replace($phrase, ' ', $remainder);
        }

        // Anything at all that carries meaning: one token of two or more
        // letters/digits, in any script. Punctuation and whitespace never
        // rescue a bare phrase, and neither does a leftover single character
        // — every one-letter Arabic word (و، ف، ل، ب، ك) is a particle, so a
        // remainder made only of those is the phrase standing alone with a
        // conjunction stuck to it. That is a fact about the language, not a
        // sufficiency threshold: real substantiation always leaves a word.
        preg_match_all('/[\p{L}\p{N}]+/u', $remainder, $matches);

        foreach ($matches[0] as $token) {
            if (mb_strlen($token) > 1) {
                return false;
            }
        }

        return true;
    }
}
