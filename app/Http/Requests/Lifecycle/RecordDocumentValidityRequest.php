<?php

namespace App\Http\Requests\Lifecycle;

use App\Services\Lifecycle\DocumentValidityRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 83 — [D] Appendix 31's nine checks over one document.
 *
 * Format only. Which answers are *permitted* per check is the appendix's own
 * qualifier question — الختم "عند الحاجة" and مطابقة الصورة للأصل "عند
 * اشتراطها" may be غير منطبق, the other seven may not — and
 * DocumentValidityRules owns that so the vocabulary lives in one place.
 */
class RecordDocumentValidityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'checks' => ['required', 'array'],
            'checks.*' => ['required', Rule::in(DocumentValidityRules::ANSWERS)],
        ];
    }

    public function messages(): array
    {
        return [
            'checks.required' => 'يجب الإجابة على بنود التحقق من صحة المستند.',
            'checks.*.in' => 'الإجابة على بند التحقق غير صالحة.',
        ];
    }
}
