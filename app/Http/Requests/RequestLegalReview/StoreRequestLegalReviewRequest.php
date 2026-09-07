<?php

namespace App\Http\Requests\RequestLegalReview;

use App\Models\RequestLegalReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 68 — recording one round of [D] Art. 21's pre-meeting legal review.
 *
 * Only `verdict` is required. Appendix 22's بطاقة السند القانوني fields are all
 * optional on purpose: the appendix itself qualifies several of them ("هل توجد
 * مدة قانونية؟", "هل توجد شروط مانعة؟") as questions that may legitimately have
 * no answer for a given matter, and Art. 21 asks the legal member to review
 * "بالقدر الذي تقتضيه طبيعة الموضوع" — requiring all eight would force an
 * invented answer, which is worse than a blank field. This is deliberately
 * unlike Stage 54's jurisdiction test and Stage 62's appeal checklist, where
 * the source text does say the classification is not finalized until every
 * question is answered.
 *
 * `legal_note` is required for four of the five verdicts. `present_with_note`
 * needs it because stating the issue is that outcome's operative half
 * ("...تستوجب العرض على اللجنة **مع بيانها**"); the three blocking verdicts
 * need it because a file returned for correction with no stated reason is
 * unactionable. Only `sound_ready` — nothing to say — may omit it.
 */
class StoreRequestLegalReviewRequest extends FormRequest
{
    /** The verdicts that cannot be recorded without the legal member's note. */
    public const VERDICTS_REQUIRING_NOTE = [
        'needs_document',
        'needs_clarification',
        'jurisdiction_note',
        'present_with_note',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'primary_legislation' => ['nullable', 'string', 'max:255'],
            'article_reference' => ['nullable', 'string', 'max:255'],
            'supplementary_decision' => ['nullable', 'string', 'max:255'],
            'committee_mandate' => ['nullable', Rule::in(RequestLegalReview::COMMITTEE_MANDATES)],
            'approving_body' => ['nullable', 'string', 'max:255'],
            'requires_central_approval' => ['nullable', Rule::in(RequestLegalReview::CENTRAL_APPROVAL_ANSWERS)],
            'legal_deadline' => ['nullable', 'string', 'max:255'],
            'prohibiting_conditions' => ['nullable', 'string'],
            'verdict' => ['required', Rule::in(RequestLegalReview::VERDICTS)],
            'legal_note' => [
                Rule::requiredIf(fn () => in_array(
                    $this->input('verdict'),
                    self::VERDICTS_REQUIRING_NOTE,
                    strict: true,
                )),
                'nullable',
                'string',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'verdict.required' => 'يرجى تحديد نتيجة المراجعة القانونية.',
            'verdict.in' => 'نتيجة المراجعة القانونية غير معروفة.',
            'committee_mandate.in' => 'قيمة اختصاص اللجنة غير صالحة.',
            'requires_central_approval.in' => 'قيمة الحاجة إلى اعتماد مركزي غير صالحة.',
            'legal_note.required' => 'يجب بيان ملاحظة العضو القانوني مع هذه النتيجة.',
            'legal_note.string' => 'ملاحظة العضو القانوني غير صالحة.',
            'primary_legislation.max' => 'التشريع الأساسي أطول من الحد المسموح به.',
            'article_reference.max' => 'رقم المادة أطول من الحد المسموح به.',
            'supplementary_decision.max' => 'القرار أو المنشور المكمل أطول من الحد المسموح به.',
            'approving_body.max' => 'جهة الاعتماد أطول من الحد المسموح به.',
            'legal_deadline.max' => 'بيان المدة القانونية أطول من الحد المسموح به.',
        ];
    }
}
