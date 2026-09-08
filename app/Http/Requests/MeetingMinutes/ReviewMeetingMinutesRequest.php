<?php

namespace App\Http\Requests\MeetingMinutes;

use App\Services\MinutesQualityRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The head's verdict on a draft: approve it, or send it back with a reason.
 *
 * Stage 78 — approving is also [D] Appendix 63's بوابة 3, so an approve
 * carries Appendix 8's one genuinely-human quality check ("خلو المحضر من
 * تعارضات داخلية"). The other fifteen are not accepted here at all: fourteen
 * are derived from the meeting's own data and the sixteenth is enforced by
 * the signature lifecycle, so neither can be supplied — or spoofed — by a
 * caller. See MinutesQualityRules.
 */
class ReviewMeetingMinutesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'changes_requested'])],
            'comment' => ['nullable', 'required_if:decision,changes_requested', 'string', 'max:2000'],
            'quality_checks' => ['required_if:decision,approve', 'array'],
            'quality_checks.'.MinutesQualityRules::REVIEWER_CHECKS[0] => ['required_if:decision,approve', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.required' => 'قرار المراجعة مطلوب.',
            'decision.in' => 'قرار المراجعة غير صالح.',
            'comment.required_if' => 'سبب طلب التعديل مطلوب.',
            'comment.max' => 'لا يمكن أن يتجاوز السبب 2000 حرف.',
            'quality_checks.required_if' => 'يجب إرسال إجابات ضوابط جودة المحضر عند اعتماده.',
            'quality_checks.array' => 'صيغة ضوابط جودة المحضر غير صالحة.',
            'quality_checks.'.MinutesQualityRules::REVIEWER_CHECKS[0].'.required_if' => 'يرجى الإجابة عن: خلو المحضر من تعارضات داخلية.',
            'quality_checks.'.MinutesQualityRules::REVIEWER_CHECKS[0].'.boolean' => 'إجابة سؤال خلو المحضر من التعارضات غير صالحة.',
        ];
    }
}
