<?php

namespace App\Http\Requests\Attachment;

use App\Models\Attachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Stage 80 — [D] Appendix 14's هيكل الملف الإلكتروني closes with
            // "ويمنع حفظ الملفات بصورة عشوائية دون تصنيف", so this is required
            // rather than defaulted: a default would be a classification the
            // uploader never made. The column itself stays nullable, so rows
            // written before this stage read as غير مصنف instead of being
            // retro-assigned a folder nobody chose.
            'file_section' => ['required', Rule::in(array_keys(Attachment::FILE_SECTIONS))],
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
            'file_section.required' => 'يجب تحديد قسم الملف الذي يحفظ فيه المستند.',
            'file_section.in' => 'قسم الملف غير صالح.',
        ];
    }
}
