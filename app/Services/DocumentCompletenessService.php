<?php

namespace App\Services;

use App\Models\Request;
use App\Models\RequestType;

/**
 * Stage 85 — the single answer to "which of [D] Appendix 57's rows must this
 * file carry, and which of them does it not?".
 *
 * Appendix 57's matrix has been seeded onto `request_types.required_documents`
 * since Stage 72 and enforced nowhere: that stage's own note said so, Stage 78
 * read it to build the officer's gate, and Stage 84's successor made the
 * submitter name which row each uploaded file answers. What was still missing
 * is the rule [F]'s own footer states outright — "لا يُعرض أي طلب على اللجنة
 * قبل استكمال المستندات المطلوبة" — and [G]'s own error message («الرجاء إرفاق
 * المستندات المطلوبة»), which no validation in this system could produce.
 *
 * MANDATORY IS NOT A NEW VOCABULARY. A row is mandatory exactly when Appendix
 * 57 states it without a qualifier, which Stage 72 stored verbatim as the
 * entry's `condition` and Stage 78's gate already reads the same way ("an
 * entry that carries a condition may honestly be answered `not_applicable`;
 * an unconditional one may not"). So this stage moves an existing bar to the
 * moment the employee can act on it; it does not raise one.
 *
 * ONE PREDICATE, THREE CALLERS — intake (StoreRequest), the agenda insertion
 * gate (MeetingController::addAgendaItem) and the readiness exception
 * (MeetingReadinessService). A refusal at submission and a refusal at the
 * agenda that disagreed about what "complete" means would be worse than
 * either, which is the same reason DecisionEligibility owns its own rule for
 * both the vote endpoint and the worklist that offers it.
 *
 * COVERAGE IS A FACT, NOT AN ATTESTATION. What counts is an attachment naming
 * the row — `attachments.required_document_key`, the same
 * IntakeGateService::documentKey() slug the officer's gate answers under. The
 * gate record is deliberately NOT consulted here: it is frozen at the moment
 * it was written and cannot regress, while the documents can (a type's matrix
 * edited through the Request Types screen adds a row no existing file
 * answers). A file that loses coverage is refused the agenda and belongs back
 * in Art. 19's استكمال loop, which is where Appendix 63 sends an incomplete
 * file anyway.
 */
class DocumentCompletenessService
{
    /**
     * The rows [D] Appendix 57 states for this type without a qualifier.
     *
     * Reads RequestType::documentOptions() rather than `required_documents`
     * directly, so the keys here are the same slugs the submitter's picker
     * offers and the officer's gate answers under — two derivations of one
     * identifier is the drift this codebase keeps refusing.
     *
     * @return array<string, array{ar: string, en: string}>
     */
    public function mandatoryDocuments(?RequestType $type): array
    {
        $mandatory = [];

        foreach ($type?->documentOptions() ?? [] as $key => $document) {
            if (($document['condition'] ?? null) === null) {
                $mandatory[$key] = ['ar' => $document['ar'], 'en' => $document['en']];
            }
        }

        return $mandatory;
    }

    /**
     * Stage 98 — the same rows minus الملف الوظيفي, which is HR's to assemble.
     *
     * [D] Appendix 6 row 3 makes الموارد البشرية مسؤول for تجهيز الملف
     * الوظيفي and gives الموظف a literal `—`, so asking a submitter to attach
     * their own البيانات الوظيفية — a record the municipality holds — was the
     * duty being discharged by the wrong party. The rows are identified by
     * the `section` Stage 80 already seeded, not by a second list:
     * EmploymentFilePreparationService answers for exactly this complement.
     *
     * Only the SUBMISSION rule narrows. refusalForRequest() below keeps
     * checking every mandatory row, so what the committee may be shown is
     * unchanged — it is assembled by two parties at two moments, which is
     * what row 3 describes.
     *
     * @return array<string, array{ar: string, en: string}>
     */
    public function mandatorySubmitterDocuments(?RequestType $type): array
    {
        $options = $type?->documentOptions() ?? [];

        return array_filter(
            $this->mandatoryDocuments($type),
            fn (string $key) => ($options[$key]['section'] ?? null) !== EmploymentFilePreparationService::SECTION,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Which of them `$coveredKeys` leaves unanswered, in the appendix's own
     * order — a reader told to bring three documents should be told them in
     * the order the matrix lists them.
     *
     * @param  iterable<string|null>  $coveredKeys
     * @return array<string, array{ar: string, en: string}>
     */
    public function uncovered(?RequestType $type, iterable $coveredKeys): array
    {
        $covered = [];
        foreach ($coveredKeys as $key) {
            if (is_string($key) && $key !== '') {
                $covered[$key] = true;
            }
        }

        return array_diff_key($this->mandatoryDocuments($type), $covered);
    }

    /**
     * The keys this request's own files already answer.
     *
     * `other` (RequestType::OTHER_DOCUMENT) and a key belonging to another
     * type simply match no row, so they cover nothing — no filtering needed
     * beyond that.
     *
     * @return list<string>
     */
    public function coveredKeys(Request $requestRecord): array
    {
        $requestRecord->loadMissing('attachments:id,request_id,required_document_key');

        return $requestRecord->attachments
            ->pluck('required_document_key')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Why this already-filed request's documents are incomplete, or null.
     *
     * Used by the agenda gate and by readiness. Quotes [F]'s own footer rule
     * so the refusal reads as the rule it enforces rather than as a generic
     * validation failure.
     */
    public function refusalForRequest(Request $requestRecord): ?string
    {
        $requestRecord->loadMissing('requestType:id,required_documents');

        $uncovered = $this->uncovered($requestRecord->requestType, $this->coveredKeys($requestRecord));

        if ($uncovered === []) {
            return null;
        }

        return 'لا يُعرض أي طلب على اللجنة قبل استكمال المستندات المطلوبة. المستندات الناقصة: '
            .$this->nameList($uncovered);
    }

    /**
     * Why this submission's own attachments are incomplete, or null.
     *
     * [G]'s own wording for the same failure, kept verbatim as its first
     * sentence — that message is what this stage exists to make producible.
     *
     * Stage 98 — over the submitter's own rows only; see
     * mandatorySubmitterDocuments() for why الملف الوظيفي left this list.
     *
     * @param  iterable<string|null>  $submittedKeys
     */
    public function refusalForSubmission(?RequestType $type, iterable $submittedKeys): ?string
    {
        $covered = [];
        foreach ($submittedKeys as $key) {
            if (is_string($key) && $key !== '') {
                $covered[$key] = true;
            }
        }

        $uncovered = array_diff_key($this->mandatorySubmitterDocuments($type), $covered);

        if ($uncovered === []) {
            return null;
        }

        return 'الرجاء إرفاق المستندات المطلوبة. المستندات الناقصة: '.$this->nameList($uncovered);
    }

    /** @param  array<string, array{ar: string, en: string}>  $documents */
    private function nameList(array $documents): string
    {
        return implode('، ', array_column($documents, 'ar'));
    }
}
