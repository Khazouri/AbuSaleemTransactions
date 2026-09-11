<?php

namespace App\Services\Lifecycle;

use App\Models\Attachment;

/**
 * Stage 83 — [D] Appendix 31's التحقق من صحة المستندات, nine checks per
 * document.
 *
 * **Deliberately not a gate.** The appendix's own verb is permissive — "و**يجوز**
 * تعليق دراسة المعاملة عند وجود شك جدي في صحة مستند جوهري إلى حين التحقق منه
 * رسميًا" — and the hold it describes already exists: Art. 105's procedural
 * suspension (Stage 78) carries the ground `document_in_doubt` (مستند أساسي
 * محل شك) verbatim. So this records the per-document answers and points at
 * that mechanism rather than inventing a second, unreconciled hold.
 *
 * **Two of the nine are conditional, and the appendix says so itself**: الختم
 * carries "عند الحاجة" and مطابقة الصورة للأصل carries "عند اشتراطها", so both
 * may honestly be answered `not_applicable`. The other seven bind — a
 * document either has an issuing body or it does not — but a `no` on any of
 * them refuses nothing; it is a recorded finding, which is what feeds Appendix
 * 30's conflict record or Art. 105's suspension.
 */
class DocumentValidityRules
{
    public const ANSWERS = ['yes', 'no', 'not_applicable'];

    /**
     * The nine, in the appendix's own order, with the two its own qualifiers
     * make conditional flagged as such.
     *
     * @var array<string, array{ar: string, en: string, conditional: bool}>
     */
    public const CHECKS = [
        'issuing_body' => ['ar' => 'الجهة المصدرة', 'en' => 'Issuing body', 'conditional' => false],
        'document_number' => ['ar' => 'رقم المستند', 'en' => 'Document number', 'conditional' => false],
        'document_date' => ['ar' => 'تاريخه', 'en' => 'Document date', 'conditional' => false],
        'signature' => ['ar' => 'التوقيع', 'en' => 'Signature', 'conditional' => false],
        'stamp' => ['ar' => 'الختم عند الحاجة', 'en' => 'Stamp, where required', 'conditional' => true],
        'copy_integrity' => ['ar' => 'سلامة النسخة', 'en' => 'Integrity of the copy', 'conditional' => false],
        'copy_matches_original' => ['ar' => 'مطابقة الصورة للأصل عند اشتراطها', 'en' => 'Copy matches the original, where required', 'conditional' => true],
        'no_unapproved_alteration' => ['ar' => 'عدم وجود تعديل أو كشط غير معتمد', 'en' => 'No unapproved alteration or erasure', 'conditional' => false],
        'linked_to_employee' => ['ar' => 'ارتباط المستند بالموظف المعني', 'en' => 'The document belongs to the employee concerned', 'conditional' => false],
    ];

    /**
     * The Arabic reason a submitted answer set may not be recorded, or null.
     *
     * @param  array<string, string>  $answers
     */
    public function refusalReason(array $answers): ?string
    {
        foreach (self::CHECKS as $key => $check) {
            $answer = $answers[$key] ?? null;

            if ($answer === null) {
                return 'يجب الإجابة على جميع بنود التحقق من صحة المستند: '.$check['ar'].' غير مجاب عليه.';
            }

            if ($answer === 'not_applicable' && ! $check['conditional']) {
                return 'البند "'.$check['ar'].'" مطلوب في كل مستند ولا يجوز اعتباره غير منطبق.';
            }
        }

        return null;
    }

    /**
     * `sound` when nothing was answered `no`, else `doubtful`.
     *
     * A verdict, never a refusal: the appendix leaves the consequence of a
     * doubt to Art. 105's suspension, which is a deliberate act by a person,
     * not something this checklist may trigger on its own.
     *
     * @param  array<string, string>|null  $answers
     */
    public function verdict(?array $answers): ?string
    {
        if ($answers === null) {
            return null;
        }

        return in_array('no', $answers, true) ? 'doubtful' : 'sound';
    }

    /**
     * The recorded card for one attachment, or null when it was never checked.
     *
     * @return array<string, mixed>|null
     */
    public function card(Attachment $attachment): ?array
    {
        $answers = $attachment->validity_checks;

        if ($answers === null) {
            return null;
        }

        return [
            'verdict' => $this->verdict($answers),
            'checked_at' => $attachment->validity_checked_at?->toIso8601String(),
            'checked_by' => $attachment->relationLoaded('validityCheckedBy') && $attachment->validityCheckedBy
                ? ['id' => $attachment->validityCheckedBy->id, 'name' => $attachment->validityCheckedBy->name]
                : null,
            'checks' => collect(self::CHECKS)
                ->map(fn (array $check, string $key) => [
                    'key' => $key,
                    'label' => $check['ar'],
                    'label_en' => $check['en'],
                    'conditional' => $check['conditional'],
                    'answer' => $answers[$key] ?? null,
                ])
                ->values()
                ->all(),
        ];
    }
}
