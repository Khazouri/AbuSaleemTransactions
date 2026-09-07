<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMeetingRequest extends FormRequest
{
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
            'meeting_type' => ['sometimes', 'required', Rule::in(['regular', 'extraordinary', 'emergency'])],
            'scheduled_at' => ['sometimes', 'required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'chairman_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'rapporteur_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'expected_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'agenda_deadline' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'required', Rule::in(['scheduled', 'completed', 'cancelled'])],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'عنوان الاجتماع مطلوب.',
            'meeting_type.in' => 'نوع الاجتماع غير صالح.',
            'scheduled_at.required' => 'موعد الاجتماع مطلوب.',
            'chairman_user_id.exists' => 'رئيس الاجتماع المحدد غير موجود.',
            'rapporteur_user_id.exists' => 'مقرر الاجتماع المحدد غير موجود.',
            'status.in' => 'حالة الاجتماع غير صالحة.',
        ];
    }
}
