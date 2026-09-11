<?php

namespace App\Http\Requests\Lifecycle;

use App\Models\RequestWithdrawal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 83 — the administration's answer to a withdrawal request.
 *
 * Format only: which outcomes are *available* turns on whether the committee
 * has already decided, which is a lookup, so WithdrawalService answers that in
 * the controller.
 *
 * The note is required for every outcome, not only the refusing one: Appendix
 * 68 requires the check that no legal reason compels the administration to
 * continue, and Appendix 69 requires the legal effect of the request to be
 * determined — both are statements somebody has to write down.
 */
class DetermineWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in(array_keys(RequestWithdrawal::OUTCOMES))],
            'determination_note' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'outcome.required' => 'يجب تحديد نتيجة البت في طلب السحب.',
            'outcome.in' => 'نتيجة البت غير معروفة.',
            'determination_note.required' => 'يجب إثبات أثر طلب السحب قانونياً.',
        ];
    }
}
