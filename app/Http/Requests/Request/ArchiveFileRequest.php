<?php

namespace App\Http\Requests\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stage 100 — where ملف الخدمة was archived (Appendix 6 row 15). Its
 * committee-file twin went on 2026-10-25, when HR became the only archiver.
 */
class ArchiveFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'location.required' => 'يجب تحديد موقع حفظ الملف.',
            'location.max' => 'لا يمكن أن يتجاوز موقع الحفظ 255 حرفاً.',
        ];
    }
}
