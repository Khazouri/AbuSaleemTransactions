<?php

namespace App\Http\Requests\Document;

use App\Models\RequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new entry in the managed documents list.
 *
 * The Arabic name is unique because it is how a type row and its entry were
 * matched when the list was first built, and two entries with one name would
 * be one document numbered twice. `section` is not accepted: it is seeded per
 * row of Appendix 57, and an entry added here files into the default folder.
 */
class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:500', Rule::unique('documents', 'name_ar')],
            'name_en' => ['nullable', 'string', 'max:500'],
            'group' => ['required', Rule::in(RequestType::DOCUMENT_GROUPS)],
            'condition_ar' => ['nullable', 'string', 'max:255'],
            'condition_en' => ['nullable', 'string', 'max:255'],
            'required' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name_ar.required' => 'اسم المستند بالعربية مطلوب.',
            'name_ar.unique' => 'يوجد مستند بهذا الاسم في القائمة.',
            'group.required' => 'تصنيف المستند مطلوب.',
            'group.in' => 'تصنيف المستند غير معروف.',
        ];
    }
}
