<?php

namespace App\Http\Requests\Committee;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCommitteeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            // Stage 48 — the committee's tashkil decision on whether its
            // rapporteur also votes.
            'rapporteur_votes' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name_ar.required' => 'اسم اللجنة بالعربية مطلوب.',
        ];
    }
}
