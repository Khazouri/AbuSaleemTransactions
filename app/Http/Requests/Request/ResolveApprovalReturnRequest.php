<?php

namespace App\Http\Requests\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stage 77 — [D] Art. 94's second half: "ينشأ إجراء إعادة معالجة يثبت سبب
 * الإعادة **والإجراء الذي اتخذ بشأنها**".
 *
 * Required, not optional: an unstated action would leave the re-processing
 * record proving only half of what the article asks it to prove. Where the file
 * goes next is not a field — Appendix 34's kind, recorded when the return came
 * in, decides that, so the recorder cannot route it by hand.
 */
class ResolveApprovalReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution_action' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'resolution_action.required' => 'يجب إثبات الإجراء الذي اتخذ بشأن الإعادة.',
            'resolution_action.max' => 'لا يمكن أن يتجاوز بيان الإجراء 5000 حرف.',
        ];
    }
}
