<?php

namespace App\Http\Requests\Guide;

use Illuminate\Foundation\Http\FormRequest;

/** Stage 27 — a new help article. */
class StoreGuideArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100', 'unique:guide_articles,code'],
            'category' => ['nullable', 'string', 'max:100'],
            // Arabic is required and English optional throughout this schema:
            // Arabic is the primary audience, English the courtesy.
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'body_ar' => ['required', 'string'],
            'body_en' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'رمز المقال مطلوب.',
            'code.unique' => 'رمز المقال مستخدم بالفعل.',
            'code.max' => 'رمز المقال طويل جداً.',
            'title_ar.required' => 'عنوان المقال بالعربية مطلوب.',
            'title_ar.max' => 'عنوان المقال بالعربية طويل جداً.',
            'body_ar.required' => 'محتوى المقال بالعربية مطلوب.',
            'sort_order.integer' => 'ترتيب العرض يجب أن يكون رقماً صحيحاً.',
        ];
    }
}
