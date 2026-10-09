<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $setting = $this->route('setting');

        return [
            'key' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('settings', 'key')->ignore($setting)],
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
