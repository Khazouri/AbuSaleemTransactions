<?php

namespace App\Http\Requests\PresentationMemo;

use Illuminate\Foundation\Http\FormRequest;

/** The four authored, non-derivable fields of a memo — see PresentationMemoCompiler's docblock. */
class UpdatePresentationMemoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'facts_summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'employment_status_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'legal_opinion' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'committee_question' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'facts_summary.string' => 'ملخص الوقائع يجب أن يكون نصاً.',
            'facts_summary.max' => 'لا يمكن أن يتجاوز ملخص الوقائع 5000 حرف.',
            'employment_status_notes.string' => 'الوضع الوظيفي يجب أن يكون نصاً.',
            'employment_status_notes.max' => 'لا يمكن أن يتجاوز الوضع الوظيفي 2000 حرف.',
            'legal_opinion.string' => 'الرأي القانوني يجب أن يكون نصاً.',
            'legal_opinion.max' => 'لا يمكن أن يتجاوز الرأي القانوني 2000 حرف.',
            'committee_question.string' => 'النقطة المطلوب البت فيها يجب أن تكون نصاً.',
            'committee_question.max' => 'لا يمكن أن تتجاوز النقطة المطلوب البت فيها 1000 حرف.',
        ];
    }
}
