<?php

namespace App\Http\Requests\Committee;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommitteeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],

            // Stage 73 — [D] Appendix 65's بطاقة تعريف اللجنة, transcribed
            // from the committee's قرار التشكيل. Its voting and minutes rules
            // went with the in-system sitting (2026-10-18).
            'formation_decision_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'formation_decision_date' => ['sometimes', 'nullable', 'date'],
            'term_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'legal_basis' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name_ar.required' => 'اسم اللجنة بالعربية مطلوب.',
        ];
    }
}
