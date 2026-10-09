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
 * is answered.
 *
 * The documents half judges the files actually attached, one verdict per file
 * after the reviewer opened it (DocumentReview, user decision 2026-10-09). It
 * used to answer Appendix 57's required-document matrix instead; that matrix
 * is still enforced, by DocumentCompletenessService at intake and before the
 * agenda. A rejected file does not strand the request: `return_missing_docs`
 * at this same stage is Art. 19's استكمال loop, and a newer upload of the same
 * document replaces it.
 */
class IntakeGateService
{
    /** The one hop that grants Art. 20's قيد, named once. */
    public const GATED_STAGE = 'requirements_check';

    public const GATED_ACTION = 'approve';

    /**
     * Appendix 20's own question for this gate, asked as an attestation
     * because the truth of the *facts* (as against the soundness of the
     * documents) is the one half of "هل الوقائع والوثائق صحيحة ومكتملة؟" that
     * no system fact can answer.
     */
    public const FACTS_CHECK = 'هل الوقائع والبيانات المقدمة صحيحة؟';

    public function __construct(private readonly DocumentReview $review) {}

    /**
     * The slug an Appendix 57 row is known by: the entry's index within the
     * seeded list plus its Arabic label's hash. Index alone would silently
     * re-point every stored key if a future seeder inserts an item, and the
     * label alone would break on a typo fix. RequestType::documentOptions()
     * and every attachment's `required_document_key` use it.
     */
    public static function documentKey(int $index, string $labelAr): string
    {
        return $index.'-'.substr(sha1($labelAr), 0, 8);
    }

    /**
     * One Arabic message at a time, in the order Appendix 20 asks its own
     * question — the documents first, then the facts.
     */
    public function refusalReason(Request $requestRecord): ?string
    {
        $record = $requestRecord->intake_gate;

        if (! is_array($record)) {
            return 'لا يجوز قيد المعاملة قبل استيفاء بوابة الرقابة الأولى: التحقق من صحة الوقائع واكتمال الوثائق.';
        }

        $documents = $this->review->refusal(
            $requestRecord,
            is_array($record['documents'] ?? null) ? $record['documents'] : [],
        );

        if ($documents !== null) {
            return $documents;
        }

        if (($record['facts_verified'] ?? false) !== true) {
            return 'لا يجوز القيد قبل التحقق من صحة الوقائع والبيانات المقدمة.';
        }

        return null;
    }

    /**
     * @param  array<int|string, string>  $verdicts
     * @return array{documents: array<int, string>, items: list<array{attachment_id: int, name: string, verdict: string}>, facts_verified: bool}
     */
    public function record(Request $requestRecord, array $verdicts, bool $factsVerified): array
    {
        return [
            ...$this->review->record($requestRecord, $verdicts),
            'facts_verified' => $factsVerified,
        ];
    }
}
