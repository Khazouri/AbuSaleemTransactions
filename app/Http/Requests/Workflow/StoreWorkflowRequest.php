<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Workflow designer — slice 3. A new named workflow, started as a draft copy of a settled version. */
class StoreWorkflowRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('workflows', 'code')],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'from_version_id' => ['required', 'integer', Rule::exists('workflow_versions', 'id')->whereNot('status', 'draft')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'رمز المسار مطلوب.',
            'code.regex' => 'رمز المسار أحرف إنجليزية صغيرة وأرقام وشرطة سفلية، ويبدأ بحرف.',
            'code.unique' => 'رمز المسار مستخدم بالفعل.',
            'name_ar.required' => 'اسم المسار بالعربية مطلوب.',
            'from_version_id.required' => 'الإصدار المصدر مطلوب.',
            'from_version_id.exists' => 'الإصدار المصدر غير موجود أو ما زال مسودة.',
        ];
    }
}
