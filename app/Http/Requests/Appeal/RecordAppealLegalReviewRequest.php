<?php

namespace App\Http\Requests\Appeal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stage 62 — Art. 75 point 4's legal-review checklist. All 5 questions are
 * required together, mirroring Stage 54's RecordJurisdictionTestRequest
 * ("not finalized until every question is answered") — a partial answer set
 * would misrepresent the review as done. The answers are informational for
 * Stage 63/64's later outcome selection, not a pass/fail gate themselves;
 * only the record's presence gates progression (see AppealController).
 */
class RecordAppealLegalReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'factual_error' => ['required', 'boolean'],
            'legal_text_violation' => ['required', 'boolean'],
            'new_documents' => ['required', 'boolean'],
            'formation_or_reasoning_defect' => ['required', 'boolean'],
            'issued_by_competent_body' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'factual_error.required' => 'يرجى الإجابة عن سؤال وجود خطأ في الوقائع.',
            'factual_error.boolean' => 'إجابة سؤال وجود خطأ في الوقائع غير صالحة.',
            'legal_text_violation.required' => 'يرجى الإجابة عن سؤال مخالفة نص قانوني.',
            'legal_text_violation.boolean' => 'إجابة سؤال مخالفة نص قانوني غير صالحة.',
            'new_documents.required' => 'يرجى الإجابة عن سؤال وجود مستندات جديدة.',
            'new_documents.boolean' => 'إجابة سؤال وجود مستندات جديدة غير صالحة.',
            'formation_or_reasoning_defect.required' => 'يرجى الإجابة عن سؤال عيب التشكيل أو النصاب أو التسبيب.',
            'formation_or_reasoning_defect.boolean' => 'إجابة سؤال عيب التشكيل أو النصاب أو التسبيب غير صالحة.',
            'issued_by_competent_body.required' => 'يرجى الإجابة عن سؤال صدور القرار عن جهة مختصة.',
            'issued_by_competent_body.boolean' => 'إجابة سؤال صدور القرار عن جهة مختصة غير صالحة.',
        ];
    }
}
