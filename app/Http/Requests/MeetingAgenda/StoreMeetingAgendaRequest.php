<?php

namespace App\Http\Requests\MeetingAgenda;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adds one agenda item to the {meeting} route-bound meeting.
 *
 * `item_type` decides which of `request_id`/`appeal_id`/`subject` is
 * required: `employee_request` (the default, and the only type that existed
 * before Stage 31) rides an existing request; `appeal` (Stage 63) rides an
 * appeal; `administrative`/`emerging` are standalone items with their own
 * subject and no request/appeal at all. Whether the appeal is actually ready
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
            'item_type' => ['sometimes', Rule::in(['employee_request', 'administrative', 'emerging', 'appeal'])],
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
            'subject' => [
                Rule::requiredIf(! in_array($itemType, ['employee_request', 'appeal'], true)),
                'nullable', 'string', 'max:255',
            ],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            // Stage 82 — [D] Appendix 24 defines exactly two levels
            // (أولوية عالية / أولوية عادية); Stage 31's high/medium/low was
            // this system's own invention.
            'priority' => ['nullable', Rule::in(['high', 'normal'])],
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
            'subject.required' => 'موضوع البند مطلوب للبنود غير المرتبطة بطلب أو تظلم.',
            'department_id.exists' => 'الإدارة المحددة غير موجودة.',
            'priority.in' => 'الأولوية غير صالحة.',
            'estimated_minutes.integer' => 'الزمن المتوقع يجب أن يكون رقماً.',
            'estimated_minutes.min' => 'الزمن المتوقع يجب أن يكون دقيقة واحدة على الأقل.',
        ];
    }
}
