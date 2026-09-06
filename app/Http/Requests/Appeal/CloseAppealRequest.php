<?php

namespace App\Http\Requests\Appeal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stage 65 — [D] Arts. 34–37's closure-field list, the fields that genuinely
 * need a human's input. `final_result_code` and `notice_status` are computed
 * in AppealController::close() itself (see its docblock) rather than
 * accepted here — they are already known from the appeal's own state, and
 * asking the closer to retype them would risk the record disagreeing with
 * what actually happened.
 */
class CloseAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'final_decision_number' => ['nullable', 'string', 'max:255'],
            'approving_body' => ['required', 'string', 'max:255'],
            'execution_date' => ['nullable', 'date'],
            'executing_body' => ['nullable', 'string', 'max:255'],
            'file_storage_location' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'final_decision_number.max' => 'لا يمكن أن يتجاوز رقم القرار النهائي 255 حرفاً.',
            'approving_body.required' => 'يجب إدخال الجهة المعتمِدة للنتيجة النهائية.',
            'approving_body.max' => 'لا يمكن أن يتجاوز اسم الجهة المعتمِدة 255 حرفاً.',
            'execution_date.date' => 'صيغة تاريخ التنفيذ غير صحيحة.',
            'executing_body.max' => 'لا يمكن أن يتجاوز اسم الجهة المنفِّذة 255 حرفاً.',
            'file_storage_location.required' => 'يجب تحديد موقع حفظ ملف التظلم.',
            'file_storage_location.max' => 'لا يمكن أن يتجاوز موقع الحفظ 255 حرفاً.',
        ];
    }
}
