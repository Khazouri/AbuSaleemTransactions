<?php

namespace App\Services\Lifecycle;

use App\Models\Attachment;

/**
 * Stage 83 — [D] Appendix 31's التحقق من صحة المستندات, nine checks per
 * document.
 *
 * **Read-only since 2026-10-07.** The checks were the direct manager's gate on
 * `forward` (user decision 2026-09-26) until the user decided the manager acts
 * with an optional note only; nothing records a card any more. Cards recorded
 * before then stay on their attachments and are still shown on the file.
 *
 * Two of the nine are conditional (الختم "عند الحاجة", مطابقة الصورة للأصل
 * "عند اشتراطها"), so a recorded card may hold `not_applicable` for them.
 */
class DocumentValidityRules
{
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
