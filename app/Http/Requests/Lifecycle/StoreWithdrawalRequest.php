<?php

namespace App\Http\Requests\Lifecycle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stage 83 — [D] Appendix 68 step 1: "يقدم طلب السحب **كتابة**".
 *
 * The written request is the record, so the reason is required — a withdrawal
 * with nothing written is exactly what the appendix's first step rules out.
 */
class StoreWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'يجب تقديم طلب السحب كتابةً مع بيان سببه.',
        ];
    }
}
