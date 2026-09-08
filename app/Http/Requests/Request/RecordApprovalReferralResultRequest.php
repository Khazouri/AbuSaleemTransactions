<?php

namespace App\Http\Requests\Request;

use App\Models\ApprovalReferral;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 80 — [D] Art. 30's inward three: تاريخ ورود النتيجة · رقم قرار
 * الاعتماد أو المستند النهائي · أي ملاحظات أو توجيهات صادرة عن جهة الاعتماد.
 *
 * `approval_decision_number` is required only when the answer was an approval:
 * a file the approving body sent back has no اعتماد decision number, and
 * demanding one would make the honest answer unrecordable — the same
 * required_if shape Stage 79's notice settings and Stage 74's deferral fields
 * both use for a field that only exists on one branch.
 */
class RecordApprovalReferralResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'result_outcome' => ['required', Rule::in(array_keys(ApprovalReferral::OUTCOMES))],
            'result_received_at' => ['required', 'date'],
            'approval_decision_number' => ['required_if:result_outcome,approved', 'nullable', 'string', 'max:255'],
            'result_note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'result_outcome.required' => 'يجب تحديد نتيجة الإحالة.',
            'result_outcome.in' => 'نتيجة الإحالة غير صالحة.',
            'result_received_at.required' => 'يجب إدخال تاريخ ورود النتيجة.',
            'result_received_at.date' => 'صيغة تاريخ ورود النتيجة غير صحيحة.',
            'approval_decision_number.required_if' => 'يجب إدخال رقم قرار الاعتماد أو المستند النهائي.',
            'approval_decision_number.max' => 'لا يمكن أن يتجاوز رقم قرار الاعتماد 255 حرفاً.',
            'result_note.max' => 'لا يمكن أن تتجاوز الملاحظات 5000 حرف.',
        ];
    }
}
