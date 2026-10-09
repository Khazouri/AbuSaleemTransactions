<?php

namespace App\Http\Requests\Request;

use Illuminate\Foundation\Http\FormRequest;

/** Stage 47 — manual correction of a request's auto-derived financial-impact flag. */
class UpdateFinancialImpactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'has_financial_impact' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'has_financial_impact.required' => 'يرجى تحديد ما إذا كان للطلب أثر مالي.',
            'has_financial_impact.boolean' => 'قيمة الأثر المالي غير صالحة.',
        ];
    }
}
