<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The paper sitting's results (user decision 2026-10-18), keyed by agenda
 * item id. Whether every item of THIS meeting has a row, and what a row needs
 * for its item's type (an appeal names no جهة الاعتماد and always states its
 * reason), are the controller's checks, since they need the meeting.
 *
 * The محضر is its scanned pages, images only, in the order the مقرر arranged
 * them (user decision 2026-10-25), so the meeting page can show them in turn.
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
            'minutes_pages' => ['required', 'array', 'min:1', 'max:50'],
            'minutes_pages.*' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
            'items' => ['required', 'array'],
            'items.*.recommendation' => ['required', 'in:approve,decline,defer'],
            'items.*.approving_authority' => ['nullable', 'in:dean,minister'],
            'items.*.note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'minutes_pages.required' => 'يجب إرفاق صفحات محضر الاجتماع.',
            'minutes_pages.array' => 'يجب إرفاق صفحات محضر الاجتماع.',
            'minutes_pages.min' => 'يجب إرفاق صفحات محضر الاجتماع.',
            'minutes_pages.max' => 'لا تتجاوز صفحات المحضر 50 صفحة.',
            'minutes_pages.*.required' => 'يجب إرفاق صفحات محضر الاجتماع.',
            'minutes_pages.*.file' => 'يرفق المحضر صوراً فقط (JPG أو PNG).',
            'minutes_pages.*.mimes' => 'يرفق المحضر صوراً فقط (JPG أو PNG).',
            'minutes_pages.*.max' => 'لا يتجاوز حجم الصفحة الواحدة 10 ميغابايت.',
            'items.required' => 'يجب إدخال نتيجة كل بند في جدول الأعمال.',
            'items.*.recommendation.required' => 'يجب اختيار توصية اللجنة لكل بند.',
            'items.*.recommendation.in' => 'توصية اللجنة: موافقة أو عدم موافقة أو تأجيل.',
            'items.*.approving_authority.in' => 'جهة الاعتماد: العميد أو الوزير.',
            'items.*.note.max' => 'لا تتجاوز الملاحظة 2000 حرف.',
        ];
    }
}
