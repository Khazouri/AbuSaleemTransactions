<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The paper sitting's results (user decision 2026-10-18), keyed by agenda
 * item id. Whether every item of THIS meeting has a row is the controller's
 * check, since it needs the meeting.
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
            // A deferred file goes back to the pending list, so it has no
            // جهة الاعتماد yet.
            'items.*.approving_authority' => ['nullable', 'required_unless:items.*.recommendation,defer', 'in:dean,minister'],
            // Art. 91 refuses an unreasoned «عدم موافقة», and a تأجيل states
            // what it waits for (Art. 34).
            'items.*.note' => ['nullable', 'required_unless:items.*.recommendation,approve', 'string', 'max:2000'],
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
            'items.*.approving_authority.required_unless' => 'يجب تحديد جهة الاعتماد: العميد أو الوزير.',
            'items.*.approving_authority.in' => 'جهة الاعتماد: العميد أو الوزير.',
            'items.*.note.required_unless' => 'يجب بيان سبب عدم الموافقة أو التأجيل.',
            'items.*.note.max' => 'لا تتجاوز الملاحظة 2000 حرف.',
        ];
    }
}
