<?php

namespace App\Http\Requests\Lifecycle;

use App\Services\Lifecycle\CorrectionRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 83 — [D] Appendix 53's مذكرة تصحيح.
 *
 * `error_kind` accepts **both** halves of the appendix's list on purpose: a
 * substantive kind is refused by CorrectionRules with the formal route named,
 * which is a more useful answer than "unknown value".
 */
class StoreCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $kinds = array_merge(
            array_keys(CorrectionRules::MATERIAL_KINDS),
            array_keys(CorrectionRules::SUBSTANTIVE_KINDS),
        );

        return [
            'error_kind' => ['required', Rule::in($kinds)],
            'detail' => ['required', 'string', 'max:2000'],
            'incorrect_value' => ['required', 'string', 'max:1000'],
            'corrected_value' => ['required', 'string', 'max:1000'],
            'memo_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'error_kind.required' => 'يجب تحديد نوع الخطأ.',
            'error_kind.in' => 'نوع الخطأ غير معروف.',
            'detail.required' => 'يجب بيان موضع الخطأ.',
            'incorrect_value.required' => 'يجب إثبات البيان الخاطئ.',
            'corrected_value.required' => 'يجب إثبات البيان الصحيح.',
        ];
    }
}
