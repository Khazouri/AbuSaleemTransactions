<?php

namespace App\Http\Requests\MeetingAgenda;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 31 — updates an existing agenda item's priority/time/subject/
 * department. `item_type` and `request_id` are deliberately not
 * editable here: switching a request item into an admin item (or back)
 * would need the same required-field dance StoreMeetingAgendaRequest does,
 * and nothing in the agenda builder's scope needs that — remove and re-add
 * instead.
 */
class UpdateMeetingAgendaItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            // Stage 82 — Appendix 24's two levels; see StoreMeetingAgendaRequest.
            'priority' => ['sometimes', 'nullable', Rule::in(['high', 'normal'])],
            'priority_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'estimated_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
        ];
    }

    public function messages(): array
    {
        return [
            'department_id.exists' => 'الإدارة المحددة غير موجودة.',
            'priority.in' => 'الأولوية غير صالحة.',
            'estimated_minutes.integer' => 'الزمن المتوقع يجب أن يكون رقماً.',
            'estimated_minutes.min' => 'الزمن المتوقع يجب أن يكون دقيقة واحدة على الأقل.',
        ];
    }
}
