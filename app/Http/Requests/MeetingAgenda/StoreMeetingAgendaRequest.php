<?php

namespace App\Http\Requests\MeetingAgenda;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adds one agenda item to the {meeting} route-bound meeting.
 *
 * `item_type` decides which of `transaction_id`/`subject` is required:
 * `employee_request` (the default, and the only type that existed before
 * Stage 31) rides an existing transaction; `administrative`/`emerging` are
 * standalone items with their own subject and no transaction at all.
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
            'item_type' => ['sometimes', Rule::in(['employee_request', 'administrative', 'emerging'])],
            'transaction_id' => [
                Rule::requiredIf($itemType === 'employee_request'),
                'nullable', 'integer', 'exists:transactions,id',
                Rule::unique('meeting_transactions', 'transaction_id')
                    ->where('meeting_id', $meeting->id),
            ],
            'subject' => [Rule::requiredIf($itemType !== 'employee_request'), 'nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'priority' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
        ];
    }

    public function messages(): array
    {
        return [
            'item_type.in' => 'نوع البند غير صالح.',
            'transaction_id.required' => 'يجب اختيار معاملة.',
            'transaction_id.exists' => 'المعاملة المحددة غير موجودة.',
            'transaction_id.unique' => 'هذه المعاملة مدرجة بالفعل في جدول أعمال الاجتماع.',
            'subject.required' => 'موضوع البند مطلوب للبنود غير المرتبطة بمعاملة.',
            'department_id.exists' => 'الإدارة المحددة غير موجودة.',
            'priority.in' => 'الأولوية غير صالحة.',
            'estimated_minutes.integer' => 'الزمن المتوقع يجب أن يكون رقماً.',
            'estimated_minutes.min' => 'الزمن المتوقع يجب أن يكون دقيقة واحدة على الأقل.',
        ];
    }
}
