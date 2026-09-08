<?php

namespace App\Http\Requests\Performance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stage 81 — the period and population Art. 106's indicators describe.
 *
 * Deliberately the same five filters Stage 24's reports screen already uses,
 * and read by ReportMetricsService::query() rather than by a second copy of the
 * clauses: the indicators tab and the detailed-report tab sit on one screen
 * under one filter bar, so they must narrow to the same requests or the screen
 * would show two different populations under one date range.
 */
class IndexPerformanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'type_id' => ['nullable', 'integer', 'exists:request_types,id'],
            'status' => ['nullable', 'string', 'exists:request_statuses,code'],
            'locale' => ['nullable', 'string', 'in:ar,en'],
        ];
    }

    public function messages(): array
    {
        return [
            'date_from.date' => 'صيغة تاريخ البداية غير صحيحة.',
            'date_to.date' => 'صيغة تاريخ النهاية غير صحيحة.',
            'date_to.after_or_equal' => 'يجب أن يكون تاريخ النهاية بعد تاريخ البداية أو مساوياً له.',
            'department_id.exists' => 'الإدارة المحددة غير موجودة.',
            'type_id.exists' => 'نوع الطلب المحدد غير موجود.',
            'status.exists' => 'الحالة المحددة غير موجودة.',
            'locale.in' => 'اللغة المحددة غير مدعومة.',
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'date_from' => $this->validated('date_from'),
            'date_to' => $this->validated('date_to'),
            'department_id' => $this->validated('department_id'),
            'type_id' => $this->validated('type_id'),
            'status' => $this->validated('status'),
        ];
    }

    /**
     * Indicator names, purposes and warning labels are rendered server-side in
     * the language the user is reading — the same call Stage 80 made for its
     * registers, and what keeps the screen and an exported copy saying the
     * same words about the same number.
     */
    public function performanceLocale(): string
    {
        return $this->validated('locale') ?? 'ar';
    }
}
