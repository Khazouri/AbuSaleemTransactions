<?php

namespace App\Http\Requests\Transaction;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates the read-only list filters before they shape the transaction query. */
class IndexTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::exists('transaction_statuses', 'code')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'type_id' => ['nullable', 'integer', Rule::exists('transaction_types', 'id')],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.exists' => 'حالة المعاملة المحددة غير صالحة.',
            'department_id.exists' => 'الإدارة المحددة غير صالحة.',
            'type_id.exists' => 'نوع المعاملة المحدد غير صالح.',
            'date_to.after_or_equal' => 'يجب أن يكون تاريخ النهاية بعد تاريخ البداية أو مساوياً له.',
        ];
    }
}
