<?php

namespace App\Http\Requests\Transaction;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates an incoming request before it becomes a workflow transaction. */
class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // Intake must not route fresh work to retired master-data rows.
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where(
                fn ($query) => $query->where('is_active', true)->whereNotNull('code'),
            )],
            'transaction_type_id' => ['required', 'integer', Rule::exists('transaction_types', 'id')->where('is_active', true)],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*.file' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:20480'],
            'attachments.*.label' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'عنوان المعاملة مطلوب.',
            'department_id.required' => 'يرجى اختيار الإدارة.',
            'department_id.exists' => 'الإدارة المحددة غير صالحة أو غير مفعّلة.',
            'transaction_type_id.required' => 'يرجى اختيار نوع المعاملة.',
            'transaction_type_id.exists' => 'نوع المعاملة المحدد غير صالح أو غير مفعّل.',
            'attachments.max' => 'لا يمكن إرفاق أكثر من 10 ملفات.',
            'attachments.*.file.required' => 'يرجى اختيار ملف للمرفق.',
            'attachments.*.file.mimes' => 'يسمح بملفات PDF وDOC وDOCX وJPG وPNG فقط.',
            'attachments.*.file.max' => 'الحد الأقصى لحجم الملف هو 20 ميجابايت.',
            'attachments.*.label.max' => 'لا يمكن أن يتجاوز وصف المرفق 255 حرفاً.',
        ];
    }
}
