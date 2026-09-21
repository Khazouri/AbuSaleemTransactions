<?php

namespace App\Services;

use App\Models\Request;

/**
 * Stage 98 — [D] Appendix 6 row 3: الموارد البشرية is مسؤول for تجهيز الملف
 * الوظيفي, الرئيس المباشر مشارك, المقرر مطلع, and الموظف a literal `—`.
 *
 * Before this stage the row had no implementation and its duty was discharged
 * by the wrong party: DocumentCompletenessService::refusalForSubmission() ran
 * at intake, so the SUBMITTER had to assemble every mandatory row of Appendix
 * 57's matrix — the employment-record extracts included — before the request
 * could be created at all. This stage moves that half of the duty rather than
 * adding a second one: intake stops asking for the service-file rows, and HR
 * answers for them here.
 *
 * WHAT الملف الوظيفي IS, WITHOUT INVENTING A LIST. RequestTypeSeeder::
 * commonDocuments() already says it in its own comment — "The seven
 * service-record extracts below are literally الملف الوظيفي; only the first
 * and last of the nine basics are not" — and Stage 80 seeded that distinction
 * as each row's `section`. So الملف الوظيفي is exactly the rows whose section
 * is `service_file` (Attachment::FILE_SECTIONS), read through
 * RequestType::documentOptions() so the keys here are the same slugs the
 * submitter's picker offers and Stage 78's gate answers under. Two
 * identifiers for one matrix is the drift this codebase keeps refusing.
 *
 * DELIBERATELY AN ACTION ON AN EXISTING STAGE. `receive_and_register` is where
 * R12 already holds the `register` row (Stage 96 left it the one registering
 * party), so HR's preparation gates the hop HR already performs. A
 * workflow_stages row of its own would renumber the chain, which is Stage-57
 * territory and far more than this row asks for.
 *
 * The three answers, the conditional rule and the derived override are all
 * Stage 78's, reused rather than re-decided — see IntakeGateService for why
 * each is what it is. What differs is only which rows are asked about, and by
 * whom: rows 3 and 4 of the same appendix are two parties assembling two
 * things at two moments.
 */
class EmploymentFilePreparationService
{
    /** The one hop HR's preparation gates, named once. */
    public const GATED_STAGE = 'receive_and_register';

    public const GATED_ACTION = 'register';

    /**
     * Appendix 14's folder that IS الملف الوظيفي — the section Stage 80 seeded
     * per row, rather than a label match, so a reworded document stays in the
     * same file. One of Attachment::FILE_SECTIONS' own keys.
     */
    public const SECTION = 'service_file';

    /** HR's own attestation, the half no stored document can answer. */
    public const ASSEMBLED_CHECK = 'هل تم تجهيز الملف الوظيفي للموظف واستكمال مستنداته؟';

    public function __construct(private readonly DocumentCompletenessService $completeness) {}

    /**
     * The service-file rows of this request's type, keyed by the shared slug.
     *
     * @return array<string, array{ar: string, en: string, conditional: bool, covered: bool}>
     */
    public function requiredDocuments(Request $requestRecord): array
    {
        $requestRecord->loadMissing('requestType:id,required_documents');
        $covered = array_fill_keys($this->completeness->coveredKeys($requestRecord), true);

        $documents = [];
        foreach ($requestRecord->requestType?->documentOptions() ?? [] as $key => $document) {
            if (($document['section'] ?? null) !== self::SECTION) {
                continue;
            }

            $documents[$key] = [
                'ar' => $document['ar'],
                'en' => $document['en'],
                'conditional' => ($document['condition'] ?? null) !== null,
                'covered' => isset($covered[$key]),
            ];
        }

        return $documents;
    }

    /**
     * Rows one of the file's own attachments already names.
     *
     * A document in the file outranks anything anyone recorded about it, in
     * both directions — Stage 78's own precedence, for Art. 104's reason
     * ("صحة المستند والاختصاص ليستا إجراءات شكلية").
     *
     * @return array<string, string>
     */
    public function derivedAnswers(Request $requestRecord): array
    {
        $derived = [];
        foreach ($this->requiredDocuments($requestRecord) as $key => $document) {
            if ($document['covered']) {
                $derived[$key] = 'present';
            }
        }

        return $derived;
    }

    /**
     * Why this file may not yet be registered, or null.
     *
     * One Arabic message at a time, the documents first and the attestation
     * last, so HR is told what to fix rather than handed a wall.
     *
     * @param  array<string, string>|null  $answers  when one is being submitted
     */
    public function refusalReason(Request $requestRecord, ?array $answers = null, ?bool $assembled = null): ?string
    {
        $required = $this->requiredDocuments($requestRecord);

        // Every type [D] covers carries the seven shared service-file rows, so
        // this is not the empty case in practice — but an administrator-created
        // type whose rows declare no section is, and a gate with nothing to
        // check must not hold the file.
        if ($required === []) {
            return null;
        }

        if ($answers === null) {
            $record = $requestRecord->employment_file;

            if (! is_array($record)) {
                return 'لا يجوز تسجيل المعاملة قبل تجهيز الملف الوظيفي للموظف من قبل إدارة الموارد البشرية.';
            }

            $answers = is_array($record['documents'] ?? null) ? $record['documents'] : [];
            $assembled = (bool) ($record['assembled'] ?? false);
        }

        $answers = [...$answers, ...$this->derivedAnswers($requestRecord)];

        foreach ($required as $key => $document) {
            $answer = $answers[$key] ?? null;

            if ($answer === null) {
                return 'لم يتم بعد تجهيز مستند الملف الوظيفي: '.$document['ar'];
            }

            if ($answer === 'missing') {
                return 'لا يجوز التسجيل قبل استكمال الملف الوظيفي. المستند الناقص: '.$document['ar'];
            }

            // Appendix 57's own qualifier is what makes لا ينطبق honest — an
            // item the source states unconditionally cannot be waived.
            if ($answer === 'not_applicable' && ! $document['conditional']) {
                return 'هذا المستند مطلوب في جميع الحالات ولا يجوز اعتباره غير منطبق: '.$document['ar'];
            }
        }

        if ($assembled !== true) {
            return 'لا يجوز التسجيل قبل إثبات تجهيز الملف الوظيفي.';
        }

        return null;
    }

    /**
     * The stored card: every service-file row with its label frozen beside its
     * answer, so it reads later as Appendix 57's own list rather than as
     * opaque keys a reader has to reassemble from a seeder. Stage 75's
     * auditRecord() precedent, Stage 78's shape.
     *
     * @param  array<string, string>  $answers
     * @return array{documents: array<string, string>, items: list<array{key: string, label_ar: string, label_en: string, conditional: bool, covered: bool, answer: string}>, assembled: bool}
     */
    public function record(Request $requestRecord, array $answers, bool $assembled): array
    {
        $answers = [...$answers, ...$this->derivedAnswers($requestRecord)];

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
                // Whether the answer came from a file rather than from HR,
                // frozen beside it: an attested `present` and a proven one are
                // different facts.
                'covered' => $document['covered'],
                'answer' => $answer,
            ];
        }

        return [
            'documents' => $documents,
            'items' => $items,
            'assembled' => $assembled,
        ];
    }
}
