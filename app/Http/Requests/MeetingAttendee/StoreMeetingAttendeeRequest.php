<?php

namespace App\Http\Requests\MeetingAttendee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Invites one extra user (beyond the committee's own membership) to the {meeting}. */
class StoreMeetingAttendeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $meeting = $this->route('meeting');

        return [
            'user_id' => [
                'required', 'integer', 'exists:users,id',
                Rule::unique('meeting_attendees', 'user_id')
                    ->where('meeting_id', $meeting->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'يجب اختيار مستخدم.',
            'user_id.exists' => 'المستخدم المحدد غير موجود.',
            'user_id.unique' => 'هذا المستخدم مدعو بالفعل إلى هذا الاجتماع.',
        ];
    }
}
