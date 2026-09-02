<?php

namespace App\Http\Requests\Appeal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stage 58 — the minimal shape an Appeal row needs to exist: which decided
 * request it targets, and (optionally) which decision, or a free-text
 * fallback when the target has no `decisions` row. No ownership, decided-
 * status, or non-duplication checks yet — those are Stage 59's scope.
 */
class StoreAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'original_request_id' => ['required', 'integer', 'exists:requests,id'],
            'original_decision_id' => ['nullable', 'integer', 'exists:decisions,id'],
            'original_decision_reference' => ['nullable', 'string', 'max:255'],
            'original_decision_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'original_request_id.required' => 'يجب تحديد الطلب موضوع التظلم.',
            'original_request_id.exists' => 'الطلب المحدد غير موجود.',
            'original_decision_id.exists' => 'القرار المحدد غير موجود.',
            'original_decision_reference.max' => 'مرجع القرار طويل جداً.',
            'original_decision_date.date' => 'تاريخ القرار غير صالح.',
        ];
    }
}
