<?php

namespace App\Http\Requests\Template;

use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100', 'unique:templates,code'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'subject_ar' => ['nullable', 'string', 'max:255'],
            'subject_en' => ['nullable', 'string', 'max:255'],
            'body_ar' => ['required', 'string'],
            'body_en' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'رمز القالب مطلوب.',
            'code.unique' => 'رمز القالب مستخدم بالفعل.',
            'name_ar.required' => 'اسم القالب بالعربية مطلوب.',
            'body_ar.required' => 'محتوى القالب بالعربية مطلوب.',
        ];
    }
}
