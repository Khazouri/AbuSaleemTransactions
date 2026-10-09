<?php

namespace App\Http\Requests\Appeal;

use Illuminate\Foundation\Http\FormRequest;

/** Mirrors Attachment\StoreAttachmentRequest verbatim — Stage 59. */
class StoreAppealAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:20480'],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'يرجى اختيار ملف للرفع.',
            'file.file' => 'الملف المرفوع غير صالح.',
            'file.mimes' => 'يسمح بملفات PDF وDOC وDOCX وJPG وPNG فقط.',
            'file.max' => 'الحد الأقصى لحجم الملف هو 20 ميجابايت.',
            'label.max' => 'لا يمكن أن يتجاوز وصف المرفق 255 حرفاً.',
        ];
    }
}
