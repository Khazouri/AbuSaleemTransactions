<?php

namespace App\Http\Requests\Decision;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 42 — which template to draft from, and in which language, for the
 * agenda item the route itself already scopes to.
 */
class ShowDecisionDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'template_id' => [
                'required',
                'integer',
                Rule::exists('templates', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'locale' => ['nullable', 'string', Rule::in(['ar', 'en'])],
        ];
    }

    public function messages(): array
    {
        return [
            'template_id.required' => 'يجب تحديد القالب المطلوب صياغته.',
            'template_id.exists' => 'القالب المحدد غير صالح.',
            'locale.in' => 'اللغة المحددة غير مدعومة.',
        ];
    }

    public function draftLocale(): string
    {
        return $this->validated('locale') ?? 'ar';
    }
}
