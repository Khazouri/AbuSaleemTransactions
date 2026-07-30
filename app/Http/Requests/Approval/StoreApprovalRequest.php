<?php

namespace App\Http\Requests\Approval;

use Illuminate\Foundation\Http\FormRequest;

/** Validates the note and Stage 19 handwritten evidence for an approval. */
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
            'signature' => [
                'required',
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
            'comment.max' => 'لا يمكن أن يتجاوز تعليق الاعتماد 5000 حرف.',
            'signature.required' => 'التوقيع الإلكتروني مطلوب لإتمام الاعتماد.',
            'signature.image' => 'يجب أن يكون التوقيع صورة صالحة.',
            'signature.mimes' => 'يجب حفظ التوقيع بصيغة PNG.',
            'signature.max' => 'لا يمكن أن يتجاوز حجم التوقيع 2 ميجابايت.',
            'signature.dimensions' => 'أبعاد صورة التوقيع غير صالحة.',
        ];
    }
}
