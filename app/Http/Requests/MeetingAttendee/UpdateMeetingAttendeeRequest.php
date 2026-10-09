<?php

namespace App\Http\Requests\MeetingAttendee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Marks whether an invited attendee showed up. Stage 102 — the RSVP is no
 * longer set here; each member answers for themselves
 * (RespondToMeetingInvitationRequest).
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
        ];
    }

    public function messages(): array
    {
        return [
        ];
    }
}
