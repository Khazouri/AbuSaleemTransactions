<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The paper sitting's results (user decision 2026-10-18), keyed by agenda
 * item id. Whether every item of THIS meeting has a row, and what a row needs
 * for its item's type (an appeal names no جهة الاعتماد and always states its
 * reason), are the controller's checks, since they need the meeting.
 */
class RecordMeetingResultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'minutes_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
            'items' => ['required', 'array'],
            'items.*.recommendation' => ['required', 'in:approve,decline,defer'],
            'items.*.approving_authority' => ['nullable', 'in:dean,minister'],
            'items.*.note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'minutes_file.required' => 'يجب إرفاق محضر الاجتماع.',
            'minutes_file.mimes' => 'يرفق المحضر بصيغة PDF أو صورة.',
            'minutes_file.max' => 'لا يتجاوز حجم المحضر 20 ميغابايت.',
            'items.required' => 'يجب إدخال نتيجة كل بند في جدول الأعمال.',
            'items.*.recommendation.required' => 'يجب اختيار توصية اللجنة لكل بند.',
            'items.*.recommendation.in' => 'توصية اللجنة: موافقة أو عدم موافقة أو تأجيل.',
            'items.*.approving_authority.in' => 'جهة الاعتماد: العميد أو الوزير.',
            'items.*.note.max' => 'لا تتجاوز الملاحظة 2000 حرف.',
        ];
    }
}
