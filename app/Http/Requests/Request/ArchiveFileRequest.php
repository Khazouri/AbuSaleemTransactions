<?php

namespace App\Http\Requests\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stage 100 — where one of Appendix 6 row 15's two files was archived.
 *
 * Shared by both halves (ملف اللجنة, ملف الخدمة): the payload is the same
 * single location, and which half is being recorded is the route, not the body.
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
