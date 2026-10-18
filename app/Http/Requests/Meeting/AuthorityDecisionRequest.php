<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * جهة الاعتماد's decision on one recommended file (user decision 2026-10-18).
 * Whether the paper قرار must be attached depends on the file's stage, so the
 * controller checks it.
 */
class AuthorityDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'required_if:decision,reject', 'string', 'max:2000'],
            'decision_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.required' => 'يجب اختيار القرار: اعتماد أو عدم اعتماد.',
            'decision.in' => 'القرار: اعتماد أو عدم اعتماد.',
            'note.required_if' => 'يجب بيان سبب عدم الاعتماد.',
            'note.max' => 'لا تتجاوز الملاحظة 2000 حرف.',
            'decision_file.mimes' => 'يرفق القرار بصيغة PDF أو صورة.',
            'decision_file.max' => 'لا يتجاوز حجم القرار 20 ميغابايت.',
        ];
    }
}
