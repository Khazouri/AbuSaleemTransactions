<?php

namespace App\Http\Requests\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates the read-only list filters before they shape the request query. */
class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::exists('request_statuses', 'code')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'type_id' => ['nullable', 'integer', Rule::exists('request_types', 'id')],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            // Stage 20 — the meeting-agenda picker looks a request up by
            // reference number or title rather than paging through the queue.
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.exists' => 'حالة الطلب المحددة غير صالحة.',
            'department_id.exists' => 'الإدارة المحددة غير صالحة.',
            'type_id.exists' => 'نوع الطلب المحدد غير صالح.',
            'date_to.after_or_equal' => 'يجب أن يكون تاريخ النهاية بعد تاريخ البداية أو مساوياً له.',
        ];
    }
}
