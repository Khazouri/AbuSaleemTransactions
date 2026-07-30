<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;

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
            'title' => ['required', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'committee_id.required' => 'يجب اختيار اللجنة.',
            'committee_id.exists' => 'اللجنة المحددة غير موجودة.',
            'title.required' => 'عنوان الاجتماع مطلوب.',
            'scheduled_at.required' => 'موعد الاجتماع مطلوب.',
        ];
    }
}
