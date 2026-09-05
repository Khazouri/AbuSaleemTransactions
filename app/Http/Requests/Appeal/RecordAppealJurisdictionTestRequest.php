<?php

namespace App\Http\Requests\Appeal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 62 — Art. 77's jurisdiction test: is the committee the competent
 * body for this appeal, or does it belong to the mayor / ministry / another
 * organizational body / a disciplinary board or court? A single structured
 * select, not free text — mirroring Stage 54's "structured, not free text"
 * jurisdiction-test pattern, applied to the one question this stage actually
 * asks rather than forced into an unrelated 6-field shape.
 */
class RecordAppealJurisdictionTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'competent_body' => [
                'required',
                'string',
                Rule::in(['committee', 'mayor', 'ministry', 'other_body', 'disciplinary_or_court']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'competent_body.required' => 'يجب تحديد الجهة المختصة بنظر هذا التظلم.',
            'competent_body.in' => 'قيمة الجهة المختصة غير صالحة.',
        ];
    }
}
