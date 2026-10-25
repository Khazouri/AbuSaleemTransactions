<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * جهة الاعتماد's paper قرار on several files of one meeting (user decision
 * 2026-10-25), keyed by agenda item id. One اشاري, one date and one set of
 * scanned pages cover them all; each file has its own اعتماد or عدم اعتماد.
 * Whether each item may be decided needs the item and the actor, so the
 * controller checks it. The pages are images only, like the محضر, so the
 * request page can show them in turn.
 */
class RecordAuthorityDecisionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.decision' => ['required', 'in:approve,reject'],
            'items.*.note' => ['nullable', 'required_if:items.*.decision,reject', 'string', 'max:2000'],
            'reference' => ['required', 'string', 'max:100'],
            'decided_on' => ['required', 'date', 'before_or_equal:today'],
            'pages' => ['required', 'array', 'min:1', 'max:50'],
            'pages.*' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'يجب اختيار طلب واحد على الأقل.',
            'items.array' => 'يجب اختيار طلب واحد على الأقل.',
            'items.min' => 'يجب اختيار طلب واحد على الأقل.',
            'items.*.decision.required' => 'يجب اختيار القرار لكل طلب: اعتماد أو عدم اعتماد.',
            'items.*.decision.in' => 'القرار: اعتماد أو عدم اعتماد.',
            'items.*.note.required_if' => 'يجب بيان سبب عدم الاعتماد.',
            'items.*.note.max' => 'لا تتجاوز الملاحظة 2000 حرف.',
            'reference.required' => 'يجب إدخال إشاري القرار.',
            'reference.max' => 'لا يتجاوز إشاري القرار 100 حرف.',
            'decided_on.required' => 'يجب إدخال تاريخ القرار.',
            'decided_on.date' => 'تاريخ القرار غير صحيح.',
            'decided_on.before_or_equal' => 'لا يكون تاريخ القرار في المستقبل.',
            'pages.required' => 'يجب إرفاق صفحات القرار.',
            'pages.array' => 'يجب إرفاق صفحات القرار.',
            'pages.min' => 'يجب إرفاق صفحات القرار.',
            'pages.max' => 'لا تتجاوز صفحات القرار 50 صفحة.',
            'pages.*.required' => 'يجب إرفاق صفحات القرار.',
            'pages.*.file' => 'يرفق القرار صوراً فقط (JPG أو PNG).',
            'pages.*.mimes' => 'يرفق القرار صوراً فقط (JPG أو PNG).',
            'pages.*.max' => 'لا يتجاوز حجم الصفحة الواحدة 10 ميغابايت.',
        ];
    }
}
