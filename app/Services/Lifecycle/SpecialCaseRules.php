<?php

namespace App\Services\Lifecycle;

use App\Models\Request;
use App\Models\RequestSpecialCase;
use Illuminate\Support\Collection;

/**
 * Stage 83 — [D] Appendix 60's six الحالات الخاصة والاستثنائية.
 *
 * Each kind's required determinations are the appendix's own words, and
 * **only the kinds it actually prohibits something for block anything**:
 *
 * - وفاة الموظف — "**لا تغلق المعاملة تلقائيًا**" → an unresolved case refuses
 *   closure.
 * - انتهاء الخدمة — "**ولا يغلق الملف دون تحديد الأثر القانوني**" → likewise,
 *   and its own three-way legal effect is the determination that lifts it.
 * - اكتشاف مستند غير صحيح بعد القرار — "**يوقف استكمال الإجراء إذا كان المستند
 *   مؤثرًا**" → refuses `approve`, but only when the recorder answered مؤثر,
 *   because the article's own condition is that qualifier.
 * - تغير التشريع — "1. **يوقف الانتقال للمرحلة التالية عند الحاجة**" → refuses
 *   `approve` only when the recorder set `halt_progress`, because "عند الحاجة"
 *   is a qualifier and an unconditional halt would be stricter than the text.
 * - النقل أثناء الدراسة and فقدان مستند — **block nothing**, deliberately: the
 *   appendix names determinations for both and no prohibition for either.
 *
 * The lost-document case's own prohibition — "**عدم إعادة إنشاء مستند من
 * الذاكرة**" — is a rule addressed to people, not a system state, so it is
 * surfaced as on-screen guidance beside the form rather than turned into a
 * tick nobody could contradict; what the system does record is the appendix's
 * own answerable half, the official replacement attempt and the substitute
 * copy's source.
 */
class SpecialCaseRules
{
    /** The kinds whose unresolved presence refuses closure. */
    public const CLOSURE_BLOCKING = [
        RequestSpecialCase::KIND_DEATH => 'لا تقفل معاملة توفي صاحبها أثناء نظرها قبل تحديد الأثر القانوني للوفاة.',
        RequestSpecialCase::KIND_SERVICE_ENDED => 'لا يقفل الملف بعد انتهاء الخدمة دون تحديد الأثر القانوني.',
    ];

    /**
     * Per kind: the determination keys the appendix names, and whether each is
     * a free-text answer or one of a fixed set.
     *
     * @var array<string, array<string, array{ar: string, en: string, options?: array<string, string>}>>
     */
    public const DETERMINATIONS = [
        RequestSpecialCase::KIND_DEATH => [
            'legal_effect' => ['ar' => 'الأثر القانوني للوفاة', 'en' => 'The legal effect of the death'],
            'procedure_continues' => [
                'ar' => 'إمكانية استمرار الإجراء من عدمها',
                'en' => 'Whether the procedure may continue',
                'options' => ['continues' => 'يستمر الإجراء', 'stops' => 'يتوقف الإجراء'],
            ],
            'transferable_rights' => ['ar' => 'الحقوق التي قد تنتقل إلى المستحقين وفق التشريع', 'en' => 'Rights that may pass to the beneficiaries'],
            'legal_review_reference' => ['ar' => 'مرجع إحالة الملف للمراجعة القانونية', 'en' => 'Reference of the referral to legal review'],
        ],
        RequestSpecialCase::KIND_SERVICE_ENDED => [
            'legal_effect' => [
                'ar' => 'أثر انتهاء الخدمة على الطلب',
                'en' => 'The effect of the service ending on the request',
                'options' => [
                    'moot' => 'يجعل الطلب غير ذي موضوع',
                    'prior_effect_remains' => 'يبقي له أثراً مالياً أو وظيفياً سابقاً',
                    'completion_for_prior_period' => 'يستوجب استكمال القرار عن مدة سابقة',
                ],
            ],
            'legal_effect_note' => ['ar' => 'بيان الأثر القانوني', 'en' => 'Statement of the legal effect'],
        ],
        RequestSpecialCase::KIND_TRANSFERRED => [
            'transfer_date' => ['ar' => 'تاريخ النقل', 'en' => 'Transfer date'],
            'right_arose_date' => ['ar' => 'تاريخ نشوء الحق محل الطلب', 'en' => 'Date the claimed right arose'],
            'competent_body_at_right' => ['ar' => 'الجهة صاحبة الاختصاص وقت نشوء الحق', 'en' => 'Body competent when the right arose'],
            'body_completing_procedure' => ['ar' => 'الجهة التي تملك استكمال الإجراء', 'en' => 'Body able to complete the procedure'],
        ],
        RequestSpecialCase::KIND_LEGISLATION_CHANGED => [
            'legislation_effective_date' => ['ar' => 'تاريخ نفاذ التشريع الجديد', 'en' => 'Effective date of the new legislation'],
            'impact_note' => ['ar' => 'أثره على المعاملة', 'en' => 'Its impact on the request'],
            'applicable_law' => ['ar' => 'القانون الواجب التطبيق', 'en' => 'The applicable law'],
        ],
        RequestSpecialCase::KIND_DOCUMENT_LOST => [
            'document_note' => ['ar' => 'تحديد المستند المفقود', 'en' => 'The lost document'],
            'replacement_attempt' => ['ar' => 'محاولة استخراج بدل رسمي', 'en' => 'Attempt to obtain an official replacement'],
            'replacement_source' => ['ar' => 'توثيق النسخة البديلة ومصدرها', 'en' => 'The substitute copy and its source'],
        ],
        RequestSpecialCase::KIND_INVALID_DOCUMENT => [
            'material' => [
                'ar' => 'هل المستند مؤثر في القرار؟',
                'en' => 'Is the document material to the decision?',
                'options' => ['yes' => 'مؤثر', 'no' => 'غير مؤثر'],
            ],
            'referred_to' => ['ar' => 'الجهة القانونية والسلطة المختصة المحال إليها', 'en' => 'The legal body and competent authority it was referred to'],
        ],
    ];

    /**
     * The Arabic reason a submitted case may not be recorded, or null.
     *
     * @param  array<string, mixed>  $determinations
     */
    public function refusalReason(string $kind, array $determinations): ?string
    {
        if (! array_key_exists($kind, RequestSpecialCase::KINDS)) {
            return 'الحالة الخاصة غير معروفة.';
        }

        foreach (self::DETERMINATIONS[$kind] as $key => $field) {
            $value = $determinations[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                return 'يجب تحديد: '.$field['ar'].'.';
            }

            if (isset($field['options']) && ! array_key_exists($value, $field['options'])) {
                return 'القيمة المحددة لبند "'.$field['ar'].'" غير صالحة.';
            }
        }

        return null;
    }

    /**
     * The Arabic reason an open special case refuses closure, or null.
     *
     * Read by RequestClosureService alongside Appendix 48's own eight
     * conditions, one message at a time.
     */
    public function closureRefusal(Request $requestRecord): ?string
    {
        foreach ($this->openCases($requestRecord) as $case) {
            if (isset(self::CLOSURE_BLOCKING[$case->case_kind])) {
                return self::CLOSURE_BLOCKING[$case->case_kind];
            }
        }

        return null;
    }

    /**
     * The Arabic reason an open special case refuses an approve, or null.
     *
     * Read through RequestController::controlGateRefusal(), so the transition
     * endpoint, the approval queue and the detail screen's preview filter all
     * agree — the one predicate Stage 78 gave those three sites.
     */
    public function approveRefusal(Request $requestRecord): ?string
    {
        foreach ($this->openCases($requestRecord) as $case) {
            if ($case->case_kind === RequestSpecialCase::KIND_INVALID_DOCUMENT
                && ($case->determinations['material'] ?? null) === 'yes') {
                return 'يوقف استكمال الإجراء لاكتشاف مستند غير صحيح مؤثر بعد القرار حتى يبت فيه.';
            }

            if ($case->case_kind === RequestSpecialCase::KIND_LEGISLATION_CHANGED && $case->halt_progress) {
                return 'يوقف الانتقال للمرحلة التالية لحين تحديد أثر التشريع الجديد والقانون الواجب التطبيق.';
            }
        }

        return null;
    }

    /** @return Collection<int, RequestSpecialCase> */
    private function openCases(Request $requestRecord): Collection
    {
        return $requestRecord->specialCases()->whereNull('resolved_at')->get();
    }
}
