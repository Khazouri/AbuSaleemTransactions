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
            'meeting_number' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'meeting_type' => ['sometimes', 'required', Rule::in(['regular', 'extraordinary', 'emergency'])],
            'scheduled_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'chairman_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'rapporteur_user_id' => ['nullable', 'integer', 'exists:users,id'],
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
            'meeting_type.in' => 'نوع الاجتماع غير صالح.',
            'scheduled_at.required' => 'موعد الاجتماع مطلوب.',
            'chairman_user_id.exists' => 'رئيس الاجتماع المحدد غير موجود.',
            'rapporteur_user_id.exists' => 'مقرر الاجتماع المحدد غير موجود.',
            'expected_duration_minutes.integer' => 'المدة المتوقعة يجب أن تكون رقماً.',
            'agenda_deadline.date' => 'الموعد النهائي لجدول الأعمال غير صالح.',
        ];
    }
}
