<?php

namespace App\Http\Requests\Request;

use App\Services\DocumentReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 78 — [D] Appendix 63's بوابة 1 (قبل القيد), one verdict per attached
 * file, keyed by attachment id.
 *
 * 2026-10-31 — and Art. 45's jurisdiction test, the other half of the same
 * gate (Stage 54), which the مقرر now answers on the same card. Optional, so
 * a re-save of the file review alone keeps the test already recorded; when
 * sent, all six answers are required together, because Art. 45 says
 * classification is not finalized until every one is answered.
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
            'jurisdiction_test' => ['sometimes', 'array'],
            'jurisdiction_test.has_legal_basis' => ['required_with:jurisdiction_test', 'boolean'],
            'jurisdiction_test.employee_covered' => ['required_with:jurisdiction_test', 'boolean'],
            'jurisdiction_test.within_municipal_jurisdiction' => ['required_with:jurisdiction_test', 'boolean'],
            'jurisdiction_test.committee_decides' => ['required_with:jurisdiction_test', 'boolean'],
            'jurisdiction_test.final_approval_authority' => ['required_with:jurisdiction_test', 'string', 'max:255'],
            'jurisdiction_test.requires_central_approval' => ['required_with:jurisdiction_test', 'boolean'],
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
            'jurisdiction_test.array' => 'صيغة إجابات اختبار الاختصاص غير صالحة.',
            'jurisdiction_test.has_legal_basis.required_with' => 'يرجى الإجابة عن سؤال السند القانوني أو التنظيمي.',
            'jurisdiction_test.has_legal_basis.boolean' => 'إجابة سؤال السند القانوني غير صالحة.',
            'jurisdiction_test.employee_covered.required_with' => 'يرجى الإجابة عن سؤال خضوع الموظف لهذا النظام.',
            'jurisdiction_test.employee_covered.boolean' => 'إجابة سؤال خضوع الموظف غير صالحة.',
            'jurisdiction_test.within_municipal_jurisdiction.required_with' => 'يرجى الإجابة عن سؤال اختصاص البلدية.',
            'jurisdiction_test.within_municipal_jurisdiction.boolean' => 'إجابة سؤال اختصاص البلدية غير صالحة.',
            'jurisdiction_test.committee_decides.required_with' => 'يرجى تحديد ما إذا كانت اللجنة تملك اتخاذ قرار أو تقتصر على الرأي أو التوصية.',
            'jurisdiction_test.committee_decides.boolean' => 'إجابة سؤال سلطة اللجنة غير صالحة.',
            'jurisdiction_test.final_approval_authority.required_with' => 'يرجى تحديد جهة الاعتماد النهائية.',
            'jurisdiction_test.final_approval_authority.max' => 'لا يمكن أن يتجاوز اسم جهة الاعتماد النهائية 255 حرفًا.',
            'jurisdiction_test.requires_central_approval.required_with' => 'يرجى الإجابة عن سؤال الموافقة المركزية.',
            'jurisdiction_test.requires_central_approval.boolean' => 'إجابة سؤال الموافقة المركزية غير صالحة.',
        ];
    }
}
