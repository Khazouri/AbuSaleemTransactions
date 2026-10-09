<?php

namespace App\Http\Requests\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stage 54 — [D] Art. 45's 6-question jurisdiction test, answered once at
 * requirements_check before approve/declare_no_jurisdiction/reject_formally
 * become available at that stage. All 6 questions are required together —
 * Art. 45 itself says classification is not finalized until every one is
 * answered, so a partial record would misrepresent that as done.
 */
class RecordJurisdictionTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'has_legal_basis' => ['required', 'boolean'],
            'employee_covered' => ['required', 'boolean'],
            'within_municipal_jurisdiction' => ['required', 'boolean'],
            'committee_decides' => ['required', 'boolean'],
            'final_approval_authority' => ['required', 'string', 'max:255'],
            'requires_central_approval' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'has_legal_basis.required' => 'يرجى الإجابة عن سؤال السند القانوني أو التنظيمي.',
            'has_legal_basis.boolean' => 'إجابة سؤال السند القانوني غير صالحة.',
            'employee_covered.required' => 'يرجى الإجابة عن سؤال خضوع الموظف لهذا النظام.',
            'employee_covered.boolean' => 'إجابة سؤال خضوع الموظف غير صالحة.',
            'within_municipal_jurisdiction.required' => 'يرجى الإجابة عن سؤال اختصاص البلدية.',
            'within_municipal_jurisdiction.boolean' => 'إجابة سؤال اختصاص البلدية غير صالحة.',
            'committee_decides.required' => 'يرجى تحديد ما إذا كانت اللجنة تملك اتخاذ قرار أو تقتصر على الرأي أو التوصية.',
            'committee_decides.boolean' => 'إجابة سؤال سلطة اللجنة غير صالحة.',
            'final_approval_authority.required' => 'يرجى تحديد جهة الاعتماد النهائية.',
            'final_approval_authority.max' => 'لا يمكن أن يتجاوز اسم جهة الاعتماد النهائية 255 حرفًا.',
            'requires_central_approval.required' => 'يرجى الإجابة عن سؤال الموافقة المركزية.',
            'requires_central_approval.boolean' => 'إجابة سؤال الموافقة المركزية غير صالحة.',
        ];
    }
}
