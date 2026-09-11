<?php

namespace App\Http\Requests\Lifecycle;

use Illuminate\Foundation\Http\FormRequest;

/** Stage 83 — closing out one of [D] Appendix 60's special cases. */
class ResolveSpecialCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution_note' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'resolution_note.required' => 'يجب إثبات ما انتهت إليه معالجة الحالة الخاصة.',
        ];
    }
}
