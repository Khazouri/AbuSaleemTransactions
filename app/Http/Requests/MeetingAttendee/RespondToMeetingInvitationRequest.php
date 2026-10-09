<?php

namespace App\Http\Requests\MeetingAttendee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Stage 102 — an invited member's own answer to the proposed meeting date. */
class RespondToMeetingInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'response' => ['required', Rule::in(['accept', 'decline'])],
        ];
    }

    public function messages(): array
    {
        return [
            'response.required' => 'يجب تحديد قبول الموعد أو الاعتذار عنه.',
            'response.in' => 'الرد على الدعوة غير صالح.',
        ];
    }
}
