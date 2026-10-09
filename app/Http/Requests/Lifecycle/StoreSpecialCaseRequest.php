<?php

namespace App\Http\Requests\Lifecycle;

use App\Models\RequestSpecialCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 83 — [D] Appendix 60.
 *
 * Format only. Which determinations a case must carry depends on which case it
 * is, and each set is the appendix's own list, so SpecialCaseRules answers that
 * in the controller rather than restating six rule sets here.
 */
class StoreSpecialCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'case_kind' => ['required', Rule::in(array_keys(RequestSpecialCase::KINDS))],
            'detail' => ['nullable', 'string', 'max:2000'],
            'determinations' => ['required', 'array'],
            'determinations.*' => ['nullable', 'string', 'max:1000'],
            'halt_progress' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'case_kind.required' => 'يجب تحديد نوع الحالة الخاصة.',
            'case_kind.in' => 'الحالة الخاصة غير معروفة.',
            'determinations.required' => 'يجب تحديد ما تستوجبه الحالة الخاصة.',
        ];
    }
}
