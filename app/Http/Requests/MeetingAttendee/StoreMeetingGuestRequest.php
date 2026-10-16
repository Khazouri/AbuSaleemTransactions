<?php

namespace App\Http\Requests\MeetingAttendee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMeetingGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'guest_title' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'يجب اختيار المدعو.',
            'user_id.exists' => 'المدعو المحدد غير موجود أو غير نشط.',
            'guest_title.required' => 'صفة المدعو مطلوبة.',
            'guest_title.max' => 'صفة المدعو يجب ألا تتجاوز 255 حرفاً.',
        ];
    }
}
