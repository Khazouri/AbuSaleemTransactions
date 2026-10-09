<?php

namespace App\Http\Requests\Request;

use App\Models\ApprovalReturn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 77 — [D] Art. 94's سبب الإعادة half, plus Art. 30's return-side fields.
 *
 * Named without the doubled word, per AGENTS.md's convention for this one
 * resource (app/Http/Requests/Request/CloseRequest.php sets the precedent).
 *
 * Format only. Whether the *kind* the recorder chose agrees with the reason
 * code's own classification in Appendix 34 is a business rule that reads the
 * appendix's table, so it lives in ApprovalReturnService::kindMismatch() and is
 * enforced by the controller — the same split StoreDecisionRequest documents
 * for requiredness that depends on a resolved tally.
 */
class RecordApprovalReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'return_kind' => ['required', Rule::in(ApprovalReturn::KINDS)],
            'return_reason_code' => ['required', Rule::in(array_keys(ApprovalReturn::REASONS))],
            // Art. 94 makes proving the reason the point of the whole action,
            // and Art. 30 records "أي ملاحظات أو توجيهات صادرة عن جهة الاعتماد"
            // — a return with neither stated is unactionable.
            'return_note' => ['required', 'string', 'max:5000'],
            'letter_number' => ['nullable', 'string', 'max:255'],
            // Art. 30's fourth recorded field — تاريخ ورود النتيجة.
            'received_at' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'return_kind.required' => 'يجب تصنيف الإعادة: شكلية أم موضوعية.',
            'return_kind.in' => 'تصنيف الإعادة غير صالح.',
            'return_reason_code.required' => 'يجب تحديد سبب الإعادة.',
            'return_reason_code.in' => 'سبب الإعادة غير صالح.',
            'return_note.required' => 'يجب إثبات ملاحظات أو توجيهات جهة الاعتماد.',
            'return_note.max' => 'لا يمكن أن تتجاوز الملاحظات 5000 حرف.',
            'letter_number.max' => 'لا يمكن أن يتجاوز رقم كتاب الإعادة 255 حرفاً.',
            'received_at.required' => 'يجب إدخال تاريخ ورود النتيجة.',
            'received_at.date' => 'صيغة تاريخ ورود النتيجة غير صحيحة.',
        ];
    }
}
