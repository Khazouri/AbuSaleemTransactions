<?php

namespace App\Http\Requests\MeetingMinutes;

use Illuminate\Foundation\Http\FormRequest;

/** Validates one attendee's handwritten signature on a meeting's minutes — same shape as StoreApprovalRequest. */
class StoreMeetingMinuteSignatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
            'signature.required' => 'التوقيع الإلكتروني مطلوب.',
            'signature.image' => 'يجب أن يكون التوقيع صورة صالحة.',
            'signature.mimes' => 'يجب حفظ التوقيع بصيغة PNG.',
            'signature.max' => 'لا يمكن أن يتجاوز حجم التوقيع 2 ميجابايت.',
            'signature.dimensions' => 'أبعاد صورة التوقيع غير صالحة.',
        ];
    }
}
