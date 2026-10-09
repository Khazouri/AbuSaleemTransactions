<?php

namespace App\Http\Requests\Approval;

use Illuminate\Foundation\Http\FormRequest;

/** Validates the note for an approval — a plain confirmation, no signature. */
class StoreApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'comment.max' => 'لا يمكن أن يتجاوز تعليق الاعتماد 5000 حرف.',
        ];
    }
}
