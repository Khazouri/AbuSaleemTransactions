<?php

namespace App\Services\Lifecycle;

/**
 * Stage 83 — [D] Appendix 33's آلية معالجة الطلبات المستعجلة.
 *
 * "**لا تعتبر المعاملة مستعجلة لمجرد طلب صاحبها ذلك.** ويمنح وصف (عاجل) فقط
 * إذا: ..." — five enumerated grounds — "**ويثبت سبب الاستعجال في النظام**".
 *
 * This structures the column Stage 82 created rather than adding a second one
 * beside it, which is that stage's own explicit handoff: its `priority_reason`
 * stays the recorded مبرر (Appendix 24 asks for free text there) and gains a
 * code from this list. Both are required for a declared عالية, because the
 * appendix asks for both: the ground restricts *when* the description may be
 * granted at all, and "يثبت سبب الاستعجال" requires the reason itself to be on
 * the record. Stage 82 left the reason optional, so this is a new enforcement
 * rather than a reversal of one.
 *
 * Three of the five have a derived counterpart in Stage 82's own
 * `priority_grounds` (a recorded legal deadline, a file returned from the
 * approving body, a deferral from a previous sitting). They are deliberately
 * **not** cross-checked against it: a legal period can be known to the
 * rapporteur before Stage 68's card records one, and refusing the declaration
 * because the corroborating record has not been written yet would make the
 * true answer unrecordable — the failure Stage 75 avoided when it let a
 * closure answer `not_applicable`. The profile reports both, so a reader can
 * see whether the declaration is corroborated.
 */
class UrgencyRules
{
    /** Appendix 33's five, in the appendix's own order. */
    public const REASONS = [
        'legal_period' => ['ar' => 'ترتبط بمدة قانونية', 'en' => 'Bound by a legal period'],
        'serious_job_harm' => ['ar' => 'يترتب على تأخيرها ضرر وظيفي جسيم', 'en' => 'Delay would cause serious job harm'],
        'returned_with_deadline' => ['ar' => 'أعيدت من جهة اعتماد بمدة محددة', 'en' => 'Returned by an approving body with a set period'],
        'official_directive' => ['ar' => 'صدر توجيه رسمي بسرعة البت', 'en' => 'An official directive to decide quickly was issued'],
        'statutory_urgency' => ['ar' => 'حالة تستوجب سرعة المعالجة وفق التشريع', 'en' => 'A case the legislation requires be handled quickly'],
    ];

    /**
     * The Arabic reason an agenda item's priority may not be recorded as it
     * stands, or null.
     *
     * Reads the *merged* item — the values the write would leave behind —
     * because an update may set the level without restating the ground, or
     * clear the ground while leaving the level high.
     *
     * @param  array<string, mixed>  $merged
     */
    public function refusalReason(array $merged): ?string
    {
        if (($merged['priority'] ?? null) !== 'high') {
            return null;
        }

        $code = $merged['priority_reason_code'] ?? null;

        if ($code === null || $code === '') {
            return 'لا تعتبر المعاملة مستعجلة لمجرد طلبها؛ يجب اختيار أحد أسباب الاستعجال المعتمدة.';
        }

        if (! array_key_exists($code, self::REASONS)) {
            return 'سبب الاستعجال غير معتمد ضمن الأسباب المنصوص عليها.';
        }

        if (trim((string) ($merged['priority_reason'] ?? '')) === '') {
            return 'يجب إثبات سبب الاستعجال في النظام.';
        }

        return null;
    }

    public function label(?string $code): ?string
    {
        return $code === null ? null : (self::REASONS[$code]['ar'] ?? $code);
    }
}
