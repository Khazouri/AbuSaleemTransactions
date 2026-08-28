<?php

namespace App\Http\Requests\Transaction;

use App\Models\TransactionType;
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
            // Types with a threshold need a grade now; otherwise the ministry
            // branch could only guess after the transaction reached approval.
            'decision_grade' => [
                Rule::requiredIf(fn () => TransactionType::query()
                    ->whereKey($this->integer('transaction_type_id'))
                    ->whereNotNull('decision_grade_threshold')
                    ->exists()),
                'nullable',
                'integer',
                'between:1,100',
            ],
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
            'decision_grade.required' => 'درجة القرار مطلوبة لهذا النوع من المعاملات.',
            'decision_grade.integer' => 'يجب أن تكون درجة القرار رقماً صحيحاً.',
            'decision_grade.between' => 'يجب أن تكون درجة القرار بين 1 و100.',
            'attachments.max' => 'لا يمكن إرفاق أكثر من 10 ملفات.',
            'attachments.*.file.required' => 'يرجى اختيار ملف للمرفق.',
            'attachments.*.file.mimes' => 'يسمح بملفات PDF وDOC وDOCX وJPG وPNG فقط.',
            'attachments.*.file.max' => 'الحد الأقصى لحجم الملف هو 20 ميجابايت.',
            'attachments.*.label.max' => 'لا يمكن أن يتجاوز وصف المرفق 255 حرفاً.',
        ];
    }
}
