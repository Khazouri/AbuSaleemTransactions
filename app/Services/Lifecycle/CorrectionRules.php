<?php

namespace App\Services\Lifecycle;

/**
 * Stage 83 — [D] Appendix 53's قواعد تصحيح الخطأ المادي.
 *
 * The appendix draws one line and this class is that line. An error in
 * "الاسم · الرقم · التاريخ · الرقم المرجعي · خطأ طباعي لا يمس جوهر القرار" is
 * fixed "**بمذكرة تصحيح معتمدة، دون تغيير جوهر النتيجة**". An error touching
 * "سبب القرار · الموظف المقصود · الاستحقاق · الدرجة · النتيجة · جهة الاعتماد"
 * "**فلا يعالج باعتباره خطأً ماديًا، بل يعاد للمسار الرسمي للمراجعة**".
 *
 * **The six substantive kinds are offered by the API only to be refused**, with
 * the formal route named — which in this system is Stage 66's reopen, whose
 * `ReopenReasonCatalog` code is already literally `material_error_correction`
 * (تصحيح خطأ جوهري). Leaving them out of the vocabulary entirely would have
 * made the refusal a generic "unknown kind" message; naming them is what lets
 * the system say *why* this one cannot be a correction memo.
 *
 * **Nothing is rewritten.** "دون تغيير جوهر النتيجة" means the memo is a
 * document added to the file: the incorrect and corrected values are recorded
 * on the memo and the original decision, minutes and reference number stand
 * exactly as issued. Appendix 46's own rule against informally amending an
 * approved محضر is the same instinct.
 */
class CorrectionRules
{
    /** The five the appendix lets a correction memo fix. */
    public const MATERIAL_KINDS = [
        'name' => ['ar' => 'خطأ في الاسم', 'en' => 'Error in the name'],
        'number' => ['ar' => 'خطأ في الرقم', 'en' => 'Error in a number'],
        'date' => ['ar' => 'خطأ في التاريخ', 'en' => 'Error in a date'],
        'reference_number' => ['ar' => 'خطأ في الرقم المرجعي', 'en' => 'Error in the reference number'],
        'typographical' => ['ar' => 'خطأ طباعي لا يمس جوهر القرار', 'en' => 'A typographical error not touching the substance'],
    ];

    /** The six it sends back to the formal route instead. */
    public const SUBSTANTIVE_KINDS = [
        'decision_reason' => ['ar' => 'سبب القرار', 'en' => 'The reason for the decision'],
        'intended_employee' => ['ar' => 'الموظف المقصود', 'en' => 'The employee concerned'],
        'entitlement' => ['ar' => 'الاستحقاق', 'en' => 'The entitlement'],
        'grade' => ['ar' => 'الدرجة', 'en' => 'The grade'],
        'result' => ['ar' => 'النتيجة', 'en' => 'The result'],
        'approving_body' => ['ar' => 'جهة الاعتماد', 'en' => 'The approving body'],
    ];

    /** The Arabic reason a correction of this kind may not be recorded, or null. */
    public function refusalReason(string $kind): ?string
    {
        if (array_key_exists($kind, self::MATERIAL_KINDS)) {
            return null;
        }

        if (array_key_exists($kind, self::SUBSTANTIVE_KINDS)) {
            return 'الخطأ في "'.self::SUBSTANTIVE_KINDS[$kind]['ar'].
                '" لا يعالج باعتباره خطأً ماديًا؛ يعاد الموضوع للمسار الرسمي للمراجعة بإعادة فتح المعاملة.';
        }

        return 'نوع الخطأ غير معروف.';
    }

    public function label(string $kind): string
    {
        return self::MATERIAL_KINDS[$kind]['ar']
            ?? self::SUBSTANTIVE_KINDS[$kind]['ar']
            ?? $kind;
    }
}
