<?php

namespace App\Http\Requests\MeetingAttendee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Marks whether an already-invited attendee showed up, and/or records their
 * RSVP — two independent concerns on the same row, so both fields are
 * optional and a caller sends whichever it's updating (see
 * MeetingController::markAttendance for how `invitation_status` stamps
 * `responded_at`).
 */
class UpdateMeetingAttendeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attended' => ['sometimes', 'required', 'boolean'],
            'invitation_status' => ['sometimes', 'required', Rule::in(['pending', 'confirmed', 'declined', 'no_response'])],
        ];
    }

    public function messages(): array
    {
        return [
            'invitation_status.in' => 'حالة الدعوة غير صالحة.',
        ];
    }
}
