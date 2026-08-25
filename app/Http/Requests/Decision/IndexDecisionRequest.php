<?php

namespace App\Http\Requests\Decision;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 25 — the filter set for the decisions register, shared with its export
 * so the downloaded file always describes the population that was on screen.
 */
class IndexDecisionRequest extends FormRequest
{
    /** The six outcomes DecisionController::record can produce (Stage 35). */
    public const OUTCOMES = [
        'approve', 'reject', 'defer',
        'conditional_approval', 'legal_opinion', 'refer_other_body',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['nullable', 'string', Rule::in(self::OUTCOMES)],
            'committee_id' => ['nullable', 'integer', Rule::exists('committees', 'id')],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'outcome.in' => 'نتيجة القرار المحددة غير صالحة.',
            'committee_id.exists' => 'اللجنة المحددة غير صالحة.',
            'date_from.date_format' => 'صيغة تاريخ البداية غير صحيحة.',
            'date_to.date_format' => 'صيغة تاريخ النهاية غير صحيحة.',
            'date_to.after_or_equal' => 'يجب أن يكون تاريخ النهاية بعد تاريخ البداية أو مساوياً له.',
            'search.max' => 'نص البحث طويل جداً.',
        ];
    }

    /**
     * Only the keys the register query understands — `per_page` is paging, not
     * a filter, so it stays out (the same split IndexReportRequest makes).
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter(
            $this->safe()->only(['outcome', 'committee_id', 'date_from', 'date_to', 'search']),
            fn ($value) => $value !== null && $value !== '',
        );
    }
}
