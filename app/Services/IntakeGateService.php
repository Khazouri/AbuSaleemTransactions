<?php

namespace App\Services;

use App\Models\Request;

/**
 * Stage 78 — [D] Appendix 63's بوابة 1 (قبل القيد), the half of it that did
 * not already exist.
 *
 * Appendix 63 asks "هل الملف صالح للدخول إلى مسار اللجنة؟" and makes the
 * refusal the system's own job: "وتمنع المنظومة الإلكترونية الانتقال إذا كانت
 * متطلبات البوابة غير مكتملة". Appendix 20 gives the same gate its question —
 * "هل الوقائع والوثائق صحيحة ومكتملة؟" — and its owners (الموظف + الرئيس
 * المباشر + الموارد البشرية / شؤون الموظفين).
 *
 * The قيد in this system is the `requirements_check → approve` hop (Stage 70,
 * which is where Art. 20's رقم إشاري is minted), and half of this gate is
 * already there: Stage 54 refuses that hop until Art. 45's jurisdiction test
 * is answered. What was missing is the documents. Stage 72 seeded Appendix
 * 57's real per-type matrix onto `request_types.required_documents` and
 * enforced nothing with it — its own note flagged exactly this as unbuilt.
 *
 * The requiredness rule is Stage 72's own data, not an invented one: Appendix
 * 57 writes its conditional items as an inline qualifier ("بحسب الموضوع"،
 * "عند الحاجة"، "متى كان ذلك شرطًا") rather than as a separate list, and Stage
 * 72 stored that verbatim as each entry's `condition`. So an entry that
 * carries a condition may honestly be answered `not_applicable`; an
 * unconditional one may not. `missing` refuses either way — and refusing does
 * not strand the file: `return_missing_docs` at this same stage is Art. 19's
 * استكمال loop, which is precisely where Appendix 63 wants an incomplete file
 * to go.
 */
class IntakeGateService
{
    /** The one hop that grants Art. 20's قيد, named once. */
    public const GATED_STAGE = 'requirements_check';

    public const GATED_ACTION = 'approve';

    /** Per-document answers. `missing` is the one that refuses. */
    public const ANSWERS = ['present', 'not_applicable', 'missing'];

    /**
     * Appendix 20's own question for this gate, asked as an attestation
     * because the truth of the *facts* (as against the presence of the
     * documents) is the one half of "هل الوقائع والوثائق صحيحة ومكتملة؟" that
     * no system fact can answer.
     */
    public const FACTS_CHECK = 'هل الوقائع والبيانات المقدمة صحيحة؟';

    /**
     * The request type's own Appendix 57 matrix, keyed by a stable slug so an
     * answer survives a reword of the Arabic label.
     *
     * The key is the entry's index within the seeded list plus its Arabic
     * label's hash — index alone would silently re-point every stored answer
     * if a future seeder inserts an item, and the label alone would break on
     * a typo fix. Both together mean a changed list produces *unanswered*
     * items (which the gate refuses) rather than wrong ones.
     *
     * @return array<string, array{ar: string, en: string, conditional: bool}>
     */
    public function requiredDocuments(Request $requestRecord): array
    {
        $documents = $requestRecord->requestType?->required_documents ?? [];

        $indexed = [];
        foreach (array_values($documents) as $index => $document) {
            $indexed[self::documentKey($index, $document['ar'] ?? '')] = [
                'ar' => $document['ar'] ?? '',
                'en' => $document['en'] ?? '',
                'conditional' => ($document['condition'] ?? null) !== null,
            ];
        }

        return $indexed;
    }

    public static function documentKey(int $index, string $labelAr): string
    {
        return $index.'-'.substr(sha1($labelAr), 0, 8);
    }

    /**
     * One Arabic message at a time, in the order Appendix 20 asks its own
     * question — the documents first, then the facts.
     *
     * @param  array<string, string>|null  $answers  the officer's per-document answers, when one is being submitted
     */
    public function refusalReason(Request $requestRecord, ?array $answers = null, ?bool $factsVerified = null): ?string
    {
        $required = $this->requiredDocuments($requestRecord);

        if ($answers === null) {
            $record = $requestRecord->intake_gate;

            if (! is_array($record)) {
                return 'لا يجوز قيد المعاملة قبل استيفاء بوابة الرقابة الأولى: التحقق من صحة الوقائع واكتمال الوثائق.';
            }

            $answers = is_array($record['documents'] ?? null) ? $record['documents'] : [];
            $factsVerified = (bool) ($record['facts_verified'] ?? false);
        }

        foreach ($required as $key => $document) {
            $answer = $answers[$key] ?? null;

            if ($answer === null) {
                return 'لم يتم بعد التحقق من المستند المطلوب: '.$document['ar'];
            }

            if ($answer === 'missing') {
                return 'لا يجوز القيد قبل اكتمال المستندات المطلوبة. المستند الناقص: '.$document['ar'];
            }

            // Appendix 57's own qualifier is what makes "لا ينطبق" honest —
            // an item the source states unconditionally cannot be waived.
            if ($answer === 'not_applicable' && ! $document['conditional']) {
                return 'هذا المستند مطلوب في جميع الحالات ولا يجوز اعتباره غير منطبق: '.$document['ar'];
            }
        }

        if ($factsVerified !== true) {
            return 'لا يجوز القيد قبل التحقق من صحة الوقائع والبيانات المقدمة.';
        }

        return null;
    }

    /**
     * The stored record is the full matrix — every required document with its
     * label frozen beside its answer — so the gate reads later as Appendix
     * 57's own list rather than a set of opaque keys a reader has to
     * reassemble from a seeder. Stage 75's `auditRecord()` precedent.
     *
     * @param  array<string, string>  $answers
     * @return array{documents: array<string, string>, items: list<array{key: string, label_ar: string, label_en: string, conditional: bool, answer: string}>, facts_verified: bool}
     */
    public function record(Request $requestRecord, array $answers, bool $factsVerified): array
    {
        $items = [];
        $documents = [];

        foreach ($this->requiredDocuments($requestRecord) as $key => $document) {
            $answer = $answers[$key] ?? 'missing';
            $documents[$key] = $answer;
            $items[] = [
                'key' => $key,
                'label_ar' => $document['ar'],
                'label_en' => $document['en'],
                'conditional' => $document['conditional'],
                'answer' => $answer,
            ];
        }

        return [
            'documents' => $documents,
            'items' => $items,
            'facts_verified' => $factsVerified,
        ];
    }
}
