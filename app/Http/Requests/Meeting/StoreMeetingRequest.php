<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'committee_id' => ['required', 'integer', 'exists:committees,id'],
            // Stage 70 — meeting_number is NOT accepted from the client any
            // more: [D] Appendix 15 defines its shape and MeetingController::
            // store() mints it, the same "never client-supplied when derivable"
            // rule appellant_user_id and original_decision_id already follow.
            // Stage 102 — meeting_type, chairman_user_id and rapporteur_user_id
            // are no longer accepted either: every meeting is the committee's
            // regular monthly sitting, chaired and reported by whoever holds
            // the chair and rapporteur seats (MeetingController::store()).
            'title' => ['required', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'expected_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'agenda_deadline' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'committee_id.required' => 'يجب اختيار اللجنة.',
            'committee_id.exists' => 'اللجنة المحددة غير موجودة.',
            'title.required' => 'عنوان الاجتماع مطلوب.',
            'scheduled_at.required' => 'موعد الاجتماع مطلوب.',
            'expected_duration_minutes.integer' => 'المدة المتوقعة يجب أن تكون رقماً.',
            'agenda_deadline.date' => 'الموعد النهائي لجدول الأعمال غير صالح.',
        ];
    }
}
