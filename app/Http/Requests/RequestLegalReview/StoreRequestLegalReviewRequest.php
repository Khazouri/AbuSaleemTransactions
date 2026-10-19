<?php

namespace App\Http\Requests\RequestLegalReview;

use App\Models\RequestLegalReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 68 — recording one round of the pre-meeting legal review.
 *
 * Since 2026-10-19 (user decision) the legal member enters his توصية and جهة
 * الاعتماد only, with an optional note. Art. 21's five verdicts and Appendix
 * 22's card are no longer accepted; older rows keep theirs.
 */
class StoreRequestLegalReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recommendation' => ['required', Rule::in(RequestLegalReview::RECOMMENDATIONS)],
            'approving_authority' => ['required', 'in:dean,minister'],
            'legal_note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'recommendation.required' => 'يرجى تحديد توصية العضو القانوني.',
            'recommendation.in' => 'توصية العضو القانوني: موافقة أو عدم موافقة.',
            'approving_authority.required' => 'يرجى تحديد جهة الاعتماد.',
            'approving_authority.in' => 'جهة الاعتماد: العميد أو الوزير.',
            'legal_note.string' => 'ملاحظة العضو القانوني غير صالحة.',
            'legal_note.max' => 'ملاحظة العضو القانوني أطول من الحد المسموح به.',
        ];
    }
}
