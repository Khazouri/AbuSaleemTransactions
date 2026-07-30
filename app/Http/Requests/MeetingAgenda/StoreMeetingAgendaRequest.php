<?php

namespace App\Http\Requests\MeetingAgenda;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Adds one transaction to the {meeting} route-bound meeting's agenda. */
class StoreMeetingAgendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $meeting = $this->route('meeting');

        return [
            'transaction_id' => [
                'required', 'integer', 'exists:transactions,id',
                Rule::unique('meeting_transactions', 'transaction_id')
                    ->where('meeting_id', $meeting->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'transaction_id.required' => 'يجب اختيار معاملة.',
            'transaction_id.exists' => 'المعاملة المحددة غير موجودة.',
            'transaction_id.unique' => 'هذه المعاملة مدرجة بالفعل في جدول أعمال الاجتماع.',
        ];
    }
}
