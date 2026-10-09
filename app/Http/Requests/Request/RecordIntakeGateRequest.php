<?php

namespace App\Http\Requests\Request;

use App\Services\DocumentReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 78 — [D] Appendix 63's بوابة 1 (قبل القيد), one verdict per attached
 * file, keyed by attachment id.
 *
 * Named without the doubled word, per AGENTS.md's convention for this one
 * resource. Format only: which files are under review, and whether the
 * reviewer opened each one, are resolved by DocumentReview — the documented
 * split `StoreDecisionRequest` and `CloseRequest` already follow.
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
            'documents.*' => [Rule::in(DocumentReview::ANSWERS)],
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
