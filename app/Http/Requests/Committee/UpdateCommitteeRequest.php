<?php

namespace App\Http\Requests\Committee;

use App\Services\CommitteeVotingRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCommitteeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            // Stage 48 — the committee's tashkil decision on whether its
            // rapporteur also votes.
            'rapporteur_votes' => ['sometimes', 'boolean'],

            // Stage 73 — [D] Appendix 65's بطاقة تعريف اللجنة, transcribed
            // from the committee's قرار التشكيل. Every field is optional: a
            // committee whose formation decision has not been entered yet is
            // reported as unconfigured by the readiness gate rather than
            // being given figures the system made up (Appendix 64).
            'formation_decision_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'formation_decision_date' => ['sometimes', 'nullable', 'date'],
            'term_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'legal_basis' => ['sometimes', 'nullable', 'string'],
            'minutes_approval_body' => ['sometimes', 'nullable', 'string', 'max:255'],
            'voting_rights_note' => ['sometimes', 'nullable', 'string'],
            'minutes_signature_rule' => ['sometimes', 'nullable', 'string'],
            'recusal_rules' => ['sometimes', 'nullable', 'string'],

            'quorum_type' => ['sometimes', 'nullable', Rule::in(CommitteeVotingRules::QUORUM_TYPES)],
            'quorum_count' => ['nullable', 'integer', 'min:1', 'max:255', 'required_if:quorum_type,count'],
            'quorum_numerator' => ['nullable', 'integer', 'min:1', 'max:255', 'required_if:quorum_type,fraction'],
            'quorum_denominator' => ['nullable', 'integer', 'min:1', 'max:255', 'required_if:quorum_type,fraction'],
            'quorum_comparator' => ['nullable', Rule::in(CommitteeVotingRules::COMPARATORS), 'required_if:quorum_type,fraction'],
            'quorum_text' => ['sometimes', 'nullable', 'string'],

            'majority_type' => ['sometimes', 'nullable', Rule::in(CommitteeVotingRules::MAJORITY_TYPES)],
            'majority_basis' => ['nullable', Rule::in(CommitteeVotingRules::MAJORITY_BASES), 'required_if:majority_type,fraction'],
            'majority_numerator' => ['nullable', 'integer', 'min:1', 'max:255', 'required_if:majority_type,fraction'],
            'majority_denominator' => ['nullable', 'integer', 'min:1', 'max:255', 'required_if:majority_type,fraction'],
            'majority_comparator' => ['nullable', Rule::in(CommitteeVotingRules::COMPARATORS), 'required_if:majority_type,fraction'],
            'majority_text' => ['sometimes', 'nullable', 'string'],

            'tie_break' => ['sometimes', 'nullable', Rule::in(CommitteeVotingRules::TIE_BREAKS)],
            'tie_break_text' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name_ar.required' => 'اسم اللجنة بالعربية مطلوب.',
            'quorum_type.in' => 'نوع النصاب غير صالح.',
            'quorum_count.required_if' => 'عدد الأعضاء اللازم للنصاب مطلوب عند تحديده بعدد.',
            'quorum_numerator.required_if' => 'بسط نسبة النصاب مطلوب عند تحديده بنسبة.',
            'quorum_denominator.required_if' => 'مقام نسبة النصاب مطلوب عند تحديده بنسبة.',
            'quorum_comparator.required_if' => 'يجب بيان ما إذا كان النص يقول «لا يقل عن» أم «أكثر من».',
            'majority_type.in' => 'قاعدة الأغلبية غير صالحة.',
            'majority_basis.required_if' => 'يجب بيان الأساس الذي تحسب عليه الأغلبية.',
            'majority_numerator.required_if' => 'بسط نسبة الأغلبية مطلوب عند تحديدها بنسبة.',
            'majority_denominator.required_if' => 'مقام نسبة الأغلبية مطلوب عند تحديدها بنسبة.',
            'majority_comparator.required_if' => 'يجب بيان ما إذا كانت الأغلبية «لا تقل عن» أم «أكثر من» النسبة.',
            'tie_break.in' => 'قاعدة معالجة تساوي الأصوات غير صالحة.',
        ];
    }
}
