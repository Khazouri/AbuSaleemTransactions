<?php

namespace App\Http\Requests\Decision;

use App\Services\DecisionReasoningRules;
use App\Services\DecisionStructureRules;
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
     * Only format-level checks live here. Whether a comment is actually
     * required depends on the tallied outcome, resolved in
     * DecisionController::record — WorkflowService enforces that the same
     * way it enforces every other transition's requires_comment rule, so
     * this request does not duplicate that check. Approving is a plain
     * confirmation — signatures have been removed from the system.
     */
    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:5000'],
            // Stage 74 — Art. 90's instrument and Appendix 27's four parts.
            // Format only: which of them is *required* depends on the tallied
            // outcome, so DecisionStructureRules decides that in the
            // controller, per this class's docblock above.
            'instrument' => ['nullable', Rule::in(DecisionStructureRules::INSTRUMENTS)],
            'decision_subject' => ['nullable', 'string', 'max:2000'],
            'decision_facts' => ['nullable', 'string', 'max:5000'],
            'decision_basis' => ['nullable', 'string', 'max:5000'],
            'decision_operative' => ['nullable', 'string', 'max:5000'],
            // Stage 74 — Appendix 28's professional refusal reason.
            'refusal_reason_code' => ['nullable', Rule::in(DecisionReasoningRules::REFUSAL_REASON_CODES)],
            // Stage 74 — Art. 34's five deferral fields.
            'deferral_reason' => ['nullable', 'string', 'max:2000'],
            'deferral_required_completion' => ['nullable', 'string', 'max:2000'],
            'deferral_responsible_body' => ['nullable', 'string', 'max:255'],
            'deferral_required_document' => ['nullable', 'string', 'max:2000'],
            'deferral_legal_period' => ['nullable', 'string', 'max:255'],
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
        ];
    }

    public function messages(): array
    {
        return [
            'comment.max' => 'لا يمكن أن يتجاوز التعليق 5000 حرف.',
            'referral_authority.max' => 'لا يمكن أن يتجاوز اسم الجهة المحال إليها 255 حرفاً.',
            'instrument.in' => 'نوع ما أصدرته اللجنة غير صالح.',
            'decision_subject.max' => 'لا يمكن أن يتجاوز موضوع القرار 2000 حرف.',
            'decision_facts.max' => 'لا يمكن أن تتجاوز الوقائع 5000 حرف.',
            'decision_basis.max' => 'لا يمكن أن يتجاوز السند 5000 حرف.',
            'decision_operative.max' => 'لا يمكن أن يتجاوز المنطوق 5000 حرف.',
            'refusal_reason_code.in' => 'سبب عدم الموافقة المحدد غير صالح.',
            'deferral_reason.max' => 'لا يمكن أن يتجاوز سبب التأجيل 2000 حرف.',
            'deferral_required_completion.max' => 'لا يمكن أن يتجاوز بيان المطلوب استكماله 2000 حرف.',
            'deferral_responsible_body.max' => 'لا يمكن أن يتجاوز اسم الجهة المسؤولة 255 حرفاً.',
            'deferral_required_document.max' => 'لا يمكن أن يتجاوز بيان المستند المطلوب 2000 حرف.',
            'deferral_legal_period.max' => 'لا يمكن أن تتجاوز المدة المقررة 255 حرفاً.',
            'template_id.exists' => 'القالب المحدد غير صالح.',
        ];
    }
}
