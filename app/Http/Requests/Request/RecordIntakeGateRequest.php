<?php

namespace App\Http\Requests\Request;

use App\Services\IntakeGateService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 78 — [D] Appendix 63's بوابة 1 (قبل القيد), answered per required
 * document.
 *
 * Named without the doubled word, per AGENTS.md's convention for this one
 * resource. Format only: *which* documents are required, and whether an
 * unconditional one may be waived, both depend on the request's own type and
 * are therefore resolved by `IntakeGateService` — the documented split
 * `StoreDecisionRequest` and `CloseRequest` already follow.
 */
class RecordIntakeGateRequest extends FormRequest
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
            'facts_verified' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'documents.present' => 'يجب إرسال إجابات التحقق من المستندات.',
            'documents.array' => 'صيغة إجابات المستندات غير صالحة.',
            'documents.*.in' => 'إجابة التحقق من المستند غير صالحة.',
            'facts_verified.required' => 'يرجى الإجابة عن سؤال صحة الوقائع والبيانات.',
            'facts_verified.boolean' => 'إجابة سؤال صحة الوقائع غير صالحة.',
        ];
    }
}
