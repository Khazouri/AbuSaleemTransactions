<?php

namespace App\Http\Requests\Request;

use App\Services\IntakeGateService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 98 — [D] Appendix 6 row 3's تجهيز الملف الوظيفي, answered per
 * service-file document.
 *
 * Named without the doubled word, per AGENTS.md's convention for this one
 * resource. Format only, for the same documented reason Stage 78's own gate
 * request gives: *which* rows are asked about, and whether an unconditional
 * one may be waived, both depend on the request's type and are resolved by
 * EmploymentFilePreparationService.
 *
 * Reuses IntakeGateService::ANSWERS rather than restating the three values —
 * the two cards answer different rows, but «موجود / لا ينطبق / ناقص» is one
 * vocabulary, and a second copy would be free to drift.
 */
class PrepareEmploymentFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'documents' => ['present', 'array'],
            'documents.*' => [Rule::in(IntakeGateService::ANSWERS)],
            'assembled' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'documents.present' => 'يجب إرسال إجابات مستندات الملف الوظيفي.',
            'documents.array' => 'صيغة إجابات مستندات الملف الوظيفي غير صالحة.',
            'documents.*.in' => 'إجابة مستند الملف الوظيفي غير صالحة.',
            'assembled.required' => 'يرجى الإجابة عن سؤال تجهيز الملف الوظيفي.',
            'assembled.boolean' => 'إجابة سؤال تجهيز الملف الوظيفي غير صالحة.',
        ];
    }
}
