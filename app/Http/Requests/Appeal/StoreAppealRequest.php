<?php

namespace App\Http\Requests\Appeal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stage 59 — the appeal's actual intake fields ([A] §9 step 1): which
 * decided request it targets, the free-text decision fallback for a target
 * with no `decisions` row, and تاريخ العلم به/أسباب الاعتراض/الطلب النهائي.
 *
 * `original_decision_id` is deliberately NOT accepted here — it is
 * auto-filled server-side from the target's latest committee decision (see
 * AppealController::store / AppealEligibility::latestDecisionFor), the same
 * "never client-supplied" treatment `appellant_user_id` already gets.
 *
 * Ownership, the decided-status restriction, and the non-duplication check
 * all depend on data this FormRequest cannot see on its own (who created the
 * target request, its current status, whether a prior appeal exists) — they
 * live in AppealEligibility, called from the controller, not here.
 */
class StoreAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'original_request_id' => ['required', 'integer', 'exists:requests,id'],
            'original_decision_reference' => ['nullable', 'string', 'max:255'],
            'original_decision_date' => ['nullable', 'date'],
            'known_at' => ['required', 'date', 'before_or_equal:today'],
            'appeal_reasons' => ['required', 'string'],
            'final_request' => ['required', 'string'],
            'new_facts_declaration' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'original_request_id.required' => 'يجب تحديد الطلب موضوع التظلم.',
            'original_request_id.exists' => 'الطلب المحدد غير موجود.',
            'original_decision_reference.max' => 'مرجع القرار طويل جداً.',
            'original_decision_date.date' => 'تاريخ القرار غير صالح.',
            'known_at.required' => 'يجب تحديد تاريخ العلم بالقرار المتظلم منه.',
            'known_at.date' => 'تاريخ العلم بالقرار غير صالح.',
            'known_at.before_or_equal' => 'لا يمكن أن يكون تاريخ العلم بالقرار في المستقبل.',
            'appeal_reasons.required' => 'يجب بيان أسباب الاعتراض.',
            'final_request.required' => 'يجب بيان الطلب النهائي للمتظلم.',
        ];
    }
}
