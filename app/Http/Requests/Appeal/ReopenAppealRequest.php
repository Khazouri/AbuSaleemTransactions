<?php

namespace App\Http\Requests\Appeal;

use App\Services\ReopenReasonCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 66 — [D] Arts. 78–79's non-reopening rule: `reason_code` must be one
 * of ReopenReasonCatalog::CODES, never a free-text-only justification. `note`
 * is optional elaboration, not a substitute for the code.
 */
class ReopenAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', Rule::in(ReopenReasonCatalog::CODES)],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason_code.required' => 'يجب اختيار سبب إعادة الفتح.',
            'reason_code.in' => 'سبب إعادة الفتح غير صالح.',
            'note.max' => 'لا يمكن أن يتجاوز التوضيح 5000 حرف.',
        ];
    }
}
