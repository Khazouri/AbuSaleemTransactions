<?php

namespace App\Http\Requests\Setting;

use App\Models\Setting;
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
            'value' => Setting::valueRules($this->input('key', $setting?->key)),
        ];
    }

    public function messages(): array
    {
        return [
            'key.required' => 'مفتاح الإعداد مطلوب.',
            'key.unique' => 'مفتاح الإعداد مستخدم بالفعل.',
            'value.required' => 'هذا الإعداد يحتاج إلى قيمة.',
            'value.integer' => 'القيمة يجب أن تكون عددًا صحيحًا.',
            'value.between' => 'عدد الأعضاء المطلوب قبولهم يجب أن يكون بين :min و:max.',
            'value.regex' => 'اللون يجب أن يكون بالصيغة #rrggbb.',
        ];
    }
}
