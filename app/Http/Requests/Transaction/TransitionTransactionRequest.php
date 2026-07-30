<?php

namespace App\Http\Requests\Transaction;

use Illuminate\Foundation\Http\FormRequest;

/** Validates the small, explicit command that asks the workflow to move. */
class TransitionTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'max:100'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'action.required' => 'إجراء سير العمل مطلوب.',
            'action.max' => 'لا يمكن أن يتجاوز إجراء سير العمل 100 حرف.',
            'comment.max' => 'لا يمكن أن يتجاوز التعليق 5000 حرف.',
        ];
    }
}
