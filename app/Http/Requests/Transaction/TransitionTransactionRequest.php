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
            'signature' => [
                'required_if:action,approve',
                'nullable',
                'file',
                'image',
                'mimes:png',
                'max:2048',
                'dimensions:min_width=2,min_height=2,max_width=2400,max_height=1200',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'action.required' => 'إجراء سير العمل مطلوب.',
            'action.max' => 'لا يمكن أن يتجاوز إجراء سير العمل 100 حرف.',
            'comment.max' => 'لا يمكن أن يتجاوز التعليق 5000 حرف.',
            'signature.required_if' => 'التوقيع الإلكتروني مطلوب لإتمام الاعتماد.',
            'signature.image' => 'يجب أن يكون التوقيع صورة صالحة.',
            'signature.mimes' => 'يجب حفظ التوقيع بصيغة PNG.',
            'signature.max' => 'لا يمكن أن يتجاوز حجم التوقيع 2 ميجابايت.',
            'signature.dimensions' => 'أبعاد صورة التوقيع غير صالحة.',
        ];
    }
}
