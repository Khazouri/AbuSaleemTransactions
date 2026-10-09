<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

class StoreSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:255', 'unique:settings,key'],
            'value' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'key.required' => 'مفتاح الإعداد مطلوب.',
            'key.unique' => 'مفتاح الإعداد مستخدم بالفعل.',
        ];
    }
}
