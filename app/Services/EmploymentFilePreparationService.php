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
 * is `service_file` (Attachment::FILE_SECTIONS); applies() reads it to decide
 * whether this type has an employment file for HR to prepare at all.
 *
 * DELIBERATELY AN ACTION ON AN EXISTING STAGE. `receive_and_register` is where
 * R12 already holds the `register` row (Stage 96 left it the one registering
 * party), so HR's preparation gates the hop HR already performs. A
 * workflow_stages row of its own would renumber the chain, which is Stage-57
 * territory and far more than this row asks for.
 *
 * Like Stage 78's gate, HR's card judges the files actually attached, one
 * verdict per file after HR opened it (DocumentReview, user decision
 * 2026-10-09), and no longer the service-file rows of Appendix 57's matrix.
 * Those rows are still demanded before the agenda by
 * DocumentCompletenessService; they are simply no longer checked at register.
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

    /**
     * The employee states their grade, entitled grade and grade date at
     * intake; HR is the party that can check them against the organisation's
     * own records, so registration waits on that confirmation too.
     */
    public const GRADE_NOT_VERIFIED = 'لا يجوز التسجيل قبل تأكد الموارد البشرية من مطابقة الدرجة الوظيفية والدرجة المستحقة وتاريخ الدرجة لسجلات الجهة.';

    public function __construct(private readonly DocumentReview $review) {}

    /**
     * Whether this request's type has any الملف الوظيفي row. A type that has
     * none (an administrator-created one whose rows declare no section) has
     * nothing for HR to prepare, and a gate with nothing to check must not
     * hold the file.
     */
    public function applies(Request $requestRecord): bool
    {
        $requestRecord->loadMissing('requestType:id,required_documents');

        foreach ($requestRecord->requestType?->documentOptions() ?? [] as $document) {
            if (($document['section'] ?? null) === self::SECTION) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why this file may not yet be registered, or null.
     *
     * One Arabic message at a time, the documents first and the attestations
     * last, so HR is told what to fix rather than handed a wall.
     */
    public function refusalReason(Request $requestRecord): ?string
    {
        if (! $this->applies($requestRecord)) {
            return null;
        }

        $record = $requestRecord->employment_file;

        if (! is_array($record)) {
            return 'لا يجوز تسجيل المعاملة قبل تجهيز الملف الوظيفي للموظف من قبل إدارة الموارد البشرية.';
        }

        $documents = $this->review->refusal(
            $requestRecord,
            is_array($record['documents'] ?? null) ? $record['documents'] : [],
        );

        if ($documents !== null) {
            return $documents;
        }

        if (($record['assembled'] ?? false) !== true) {
            return 'لا يجوز التسجيل قبل إثبات تجهيز الملف الوظيفي.';
        }

        if (($record['grade_verified'] ?? false) !== true) {
            return self::GRADE_NOT_VERIFIED;
        }

        return null;
    }

    /**
     * The stored card. The grade data is frozen beside HR's confirmation, so
     * the card shows the values that were actually checked.
     *
     * @param  array<int|string, string>  $verdicts
     * @return array{documents: array<int, string>, items: list<array{attachment_id: int, name: string, verdict: string}>, assembled: bool, grade_verified: bool, grade: array{decision_grade: int|null, entitled_grade: int|null, grade_date: string|null}}
     */
    public function record(Request $requestRecord, array $verdicts, bool $assembled, bool $gradeVerified): array
    {
        return [
            ...$this->review->record($requestRecord, $verdicts),
            'assembled' => $assembled,
            'grade_verified' => $gradeVerified,
            'grade' => [
                'decision_grade' => $requestRecord->decision_grade,
                'entitled_grade' => $requestRecord->entitled_grade,
                'grade_date' => $requestRecord->grade_date?->toDateString(),
            ],
        ];
    }
}
