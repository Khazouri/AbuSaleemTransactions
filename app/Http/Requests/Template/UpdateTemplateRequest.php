<?php

namespace App\Http\Requests\Template;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $template = $this->route('template');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('templates', 'code')->ignore($template)],
            'category' => ['nullable', 'string', 'max:50'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'subject_ar' => ['nullable', 'string', 'max:255'],
            'subject_en' => ['nullable', 'string', 'max:255'],
            'body_ar' => ['sometimes', 'required', 'string'],
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
