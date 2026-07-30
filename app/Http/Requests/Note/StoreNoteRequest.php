<?php

namespace App\Http\Requests\Note;

use Illuminate\Foundation\Http\FormRequest;

/** Keeps transaction discussions concise and safe to render later in the detail view. */
class StoreNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'is_internal' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'نص الملاحظة مطلوب.',
            'body.max' => 'لا يمكن أن تتجاوز الملاحظة 5000 حرف.',
        ];
    }
}
