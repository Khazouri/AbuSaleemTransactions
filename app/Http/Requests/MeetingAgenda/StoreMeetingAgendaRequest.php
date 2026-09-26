<?php

namespace App\Http\Requests\MeetingAgenda;

use App\Services\Lifecycle\UrgencyRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adds one agenda item to the {meeting} route-bound meeting.
 *
 * `item_type` decides which of `request_id`/`appeal_id` is required:
 * `employee_request` (the default) rides a request from the committee's
 * pending list; `appeal` (Stage 63) rides an appeal. Stage 102 removed the
 * free-standing `administrative`/`emerging` items. Whether the appeal is actually ready
 * for committee presentation (status `legal_review`) is a business rule, not
 * a validation rule — enforced in MeetingController::addAgendaItem.
 */
class StoreMeetingAgendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $meeting = $this->route('meeting');
        $itemType = $this->input('item_type', 'employee_request');

        return [
            'item_type' => ['sometimes', Rule::in(['employee_request', 'appeal'])],
            'request_id' => [
                Rule::requiredIf($itemType === 'employee_request'),
                'nullable', 'integer', 'exists:requests,id',
                Rule::unique('meeting_requests', 'request_id')
                    ->where('meeting_id', $meeting->id),
            ],
            'appeal_id' => [
                Rule::requiredIf($itemType === 'appeal'),
                'nullable', 'integer', 'exists:appeals,id',
                Rule::unique('meeting_requests', 'appeal_id')
                    ->where('meeting_id', $meeting->id),
            ],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            // Stage 82 — [D] Appendix 24 defines exactly two levels
            // (أولوية عالية / أولوية عادية); Stage 31's high/medium/low was
            // this system's own invention.
            'priority' => ['nullable', Rule::in(['high', 'normal'])],
            // Stage 83 — [D] Appendix 33's five enumerated grounds. Format
            // only; UrgencyRules decides when one is required.
            'priority_reason_code' => ['nullable', Rule::in(array_keys(UrgencyRules::REASONS))],
            'priority_reason' => ['nullable', 'string', 'max:500'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
        ];
    }

    public function messages(): array
    {
        return [
            'item_type.in' => 'نوع البند غير صالح.',
            'request_id.required' => 'يجب اختيار طلب.',
            'request_id.exists' => 'الطلب المحدد غير موجود.',
            'request_id.unique' => 'هذا الطلب مدرج بالفعل في جدول أعمال الاجتماع.',
            'appeal_id.required' => 'يجب اختيار تظلم.',
            'appeal_id.exists' => 'التظلم المحدد غير موجود.',
            'appeal_id.unique' => 'هذا التظلم مدرج بالفعل في جدول أعمال الاجتماع.',
            'department_id.exists' => 'الإدارة المحددة غير موجودة.',
            'priority.in' => 'الأولوية غير صالحة.',
            'estimated_minutes.integer' => 'الزمن المتوقع يجب أن يكون رقماً.',
            'estimated_minutes.min' => 'الزمن المتوقع يجب أن يكون دقيقة واحدة على الأقل.',
        ];
    }
}
