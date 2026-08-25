<?php

namespace App\Http\Requests\MeetingMinutes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The head's verdict on a draft: approve it, or send it back with a reason. */
class ReviewMeetingMinutesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'changes_requested'])],
            'comment' => ['nullable', 'required_if:decision,changes_requested', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.required' => 'قرار المراجعة مطلوب.',
            'decision.in' => 'قرار المراجعة غير صالح.',
            'comment.required_if' => 'سبب طلب التعديل مطلوب.',
            'comment.max' => 'لا يمكن أن يتجاوز السبب 2000 حرف.',
        ];
    }
}
