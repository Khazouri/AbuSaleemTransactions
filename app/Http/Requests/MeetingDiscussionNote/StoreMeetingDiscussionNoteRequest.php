<?php

namespace App\Http\Requests\MeetingDiscussionNote;

use Illuminate\Foundation\Http\FormRequest;

/** Stage 34 — one entry posted to the live runner's discussion feed. */
class StoreMeetingDiscussionNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'نص الملاحظة مطلوب.',
            'note.max' => 'لا يمكن أن تتجاوز الملاحظة 5000 حرف.',
        ];
    }
}
