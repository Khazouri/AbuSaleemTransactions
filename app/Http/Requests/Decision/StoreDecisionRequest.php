<?php

namespace App\Http\Requests\Decision;

use Illuminate\Foundation\Http\FormRequest;

/** Records the committee's binding decision for an agenda item. */
class StoreDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only format-level checks live here. Whether a comment or signature is
     * actually required depends on the tallied outcome, resolved in
     * DecisionController::record — WorkflowService enforces that the same
     * way it enforces every other transition's requires_comment/signature
     * rule, so this request does not duplicate that check.
     */
    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:5000'],
            'signature' => [
                'nullable',
                'file',
                'image',
                'mimes:png',
                'max:2048',
                'dimensions:min_width=2,min_height=2,max_width=2400,max_height=1200',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'comment.max' => 'لا يمكن أن يتجاوز التعليق 5000 حرف.',
            'signature.image' => 'يجب أن يكون التوقيع صورة صالحة.',
            'signature.mimes' => 'يجب حفظ التوقيع بصيغة PNG.',
            'signature.max' => 'لا يمكن أن يتجاوز حجم التوقيع 2 ميجابايت.',
            'signature.dimensions' => 'أبعاد صورة التوقيع غير صالحة.',
        ];
    }
}
