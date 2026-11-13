<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMeetingRequest extends FormRequest
{
    use StoresMeetingTimesInUtc;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Stage 70 — meeting_number is NOT accepted from the client any
            // more: [D] Appendix 15 defines its shape and MeetingController::
            // store() mints it, the same "never client-supplied when derivable"
            // rule appellant_user_id and original_decision_id already follow.
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            // High open items (2026-11-13) — a date change cannot move it into the past either.
            'scheduled_at' => ['sometimes', 'required', 'date', 'after:now'],
            'location' => ['nullable', 'string', 'max:255'],
            'expected_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'agenda_deadline' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            // Stage 82 — Appendix 24's documented justification for an agenda
            // that departs from Art. 83's ordering; see AgendaOrderingService.
            'agenda_order_justification' => ['nullable', 'string'],
            // Stage 102 — `scheduled` is reached only by the members accepting
            // the date (MeetingController::respond()), and `completed` only by
            // the مقرر entering the results (MeetingResultsController), so a
            // person sets nothing here but a cancellation.
            'status' => ['sometimes', 'required', Rule::in(['cancelled'])],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'عنوان الاجتماع مطلوب.',
            'scheduled_at.required' => 'موعد الاجتماع مطلوب.',
            'scheduled_at.after' => 'يجب أن يكون موعد الاجتماع في المستقبل.',
            'status.in' => 'حالة الاجتماع غير صالحة.',
        ];
    }
}
