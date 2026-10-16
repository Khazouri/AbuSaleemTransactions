<?php

namespace App\Http\Requests\Setting;

use App\Models\Setting;
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
            'value' => Setting::valueRules($this->input('key')),
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
        ];
    }
}
