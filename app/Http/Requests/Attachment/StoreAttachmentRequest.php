<?php

namespace App\Http\Requests\Attachment;

use Illuminate\Foundation\Http\FormRequest;

/** Validates uploads before a file is allowed into private application storage. */
class StoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `max` is kilobytes. Keeping this server-side is essential: a
            // browser's preflight check is only a convenience, not a boundary.
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
