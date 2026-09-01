<?php

namespace App\Http\Requests\Decision;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Stage 50 — [D] Art. 28's minutes-content list: which body the
            // matter was referred to, if any. Optional on every outcome, not
            // restricted to refer_other_body/no_jurisdiction — the committee
            // head fills it in when relevant.
            'referral_authority' => ['nullable', 'string', 'max:255'],
            // Stage 35 — which reusable text the comment was drafted from, if
            // any; must be an active template, but not necessarily a
            // `category=decision` one — the recording UI narrows the picker,
            // this only guards against a stale or fabricated id.
            'template_id' => [
                'nullable',
                'integer',
                Rule::exists('templates', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
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
            'referral_authority.max' => 'لا يمكن أن يتجاوز اسم الجهة المحال إليها 255 حرفاً.',
            'template_id.exists' => 'القالب المحدد غير صالح.',
            'signature.image' => 'يجب أن يكون التوقيع صورة صالحة.',
            'signature.mimes' => 'يجب حفظ التوقيع بصيغة PNG.',
            'signature.max' => 'لا يمكن أن يتجاوز حجم التوقيع 2 ميجابايت.',
            'signature.dimensions' => 'أبعاد صورة التوقيع غير صالحة.',
        ];
    }
}
