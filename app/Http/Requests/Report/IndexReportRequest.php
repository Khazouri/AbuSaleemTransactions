<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 24 — the filter set shared by the dashboard, the report table and the
 * export. One request class for all three so a filter can never be accepted on
 * one endpoint and silently dropped on another.
 */
class IndexReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'type_id' => ['nullable', 'integer', Rule::exists('request_types', 'id')],
            'status' => ['nullable', 'string', Rule::exists('request_statuses', 'code')],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'department_id.exists' => 'الإدارة المحددة غير صالحة.',
            'type_id.exists' => 'نوع الطلب المحدد غير صالح.',
            'status.exists' => 'الحالة المحددة غير صالحة.',
            'date_from.date_format' => 'صيغة تاريخ البداية غير صحيحة.',
            'date_to.date_format' => 'صيغة تاريخ النهاية غير صحيحة.',
            'date_to.after_or_equal' => 'يجب أن يكون تاريخ النهاية بعد تاريخ البداية أو مساوياً له.',
        ];
    }

    /**
     * Only the keys ReportMetricsService understands — `per_page` is paging,
     * not a filter, and letting it into the filter array would give the same
     * population two different cache entries.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter(
            $this->safe()->only(['department_id', 'type_id', 'status', 'date_from', 'date_to']),
            fn ($value) => $value !== null && $value !== '',
        );
    }
}
