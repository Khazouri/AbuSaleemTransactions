<?php

namespace App\Http\Requests\Meeting;

use App\Services\RequestExecutionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 76 — النموذج 17's أمر تنفيذ قرار وظيفي plus Appendix 70's دليل التنفيذ.
 *
 * Format only, the split StoreDecisionRequest's own docblock established:
 * whether the nominated documents actually belong to this request, and whether
 * Art. 97's financial referral binds, both depend on state a FormRequest cannot
 * see, so RequestExecutionService owns them.
 *
 * Four of النموذج 17's card fields are required. Appendix 70's premise is that
 * the executing body's claim must be substantiated, and each of the four is
 * knowable by whoever received Art. 95's execution file — الجهة المنفذة، الإجراء
 * المنفذ، تاريخ سريان الأثر (Art. 95 item 5 / Appendix 52 item 2's تاريخ النفاذ)
 * and جهة الاعتماد. رقم/تاريخ الاعتماد stay optional because the approving
 * body's own numbering is Stage 77's scope (see Stage 70's note on Appendix 30)
 * and requiring it would demand data no path supplies yet.
 *
 * `execution_document_attached` is absent from the accepted checklist keys by
 * design: the service derives it from the nominated evidence, because Appendix
 * 70 refuses to let it be an attestation.
 */
class ExecuteMeetingOutputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'executing_body' => ['required', 'string', 'max:255'],
            'action_taken' => ['required', 'string', 'max:2000'],
            'effective_date' => ['required', 'date'],
            'approving_body' => ['required', 'string', 'max:255'],
            'approval_number' => ['nullable', 'string', 'max:255'],
            'approval_date' => ['nullable', 'date'],
            'financial_effect_note' => ['nullable', 'string', 'max:2000'],
            'checklist' => ['required', 'array'],
            // Appendix 70 — at least one document, always. The array-level
            // `min:1` is the shape of the rule; the service states it in the
            // appendix's own words when the list arrives empty by another path.
            'evidence' => ['required', 'array', 'min:1'],
            'evidence.*.attachment_id' => ['required', 'integer', 'exists:attachments,id'],
            'evidence.*.evidence_type' => ['required', Rule::in(array_keys(RequestExecutionService::EVIDENCE_TYPES))],
        ];

        // Every one of النموذج 17's executor-answered checks must carry an
        // answer — a partially-filled follow-up list would misrepresent an
        // unanswered question as a passed one, the reasoning Stage 54 used for
        // Art. 45's six-question test and Stage 75 for Appendix 47.
        foreach (RequestExecutionService::EXECUTOR_CHECKS as $check) {
            $rules["checklist.{$check}"] = ['required', Rule::in(RequestExecutionService::ANSWERS)];
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [
            'executing_body.required' => 'يجب إدخال الجهة المنفذة.',
            'executing_body.max' => 'لا يمكن أن يتجاوز اسم الجهة المنفذة 255 حرفاً.',
            'action_taken.required' => 'يجب بيان الإجراء الذي تم تنفيذه.',
            'action_taken.max' => 'لا يمكن أن يتجاوز بيان الإجراء المنفذ 2000 حرف.',
            'effective_date.required' => 'يجب إدخال تاريخ سريان الأثر.',
            'effective_date.date' => 'صيغة تاريخ سريان الأثر غير صحيحة.',
            'approving_body.required' => 'يجب إدخال جهة الاعتماد.',
            'approving_body.max' => 'لا يمكن أن يتجاوز اسم جهة الاعتماد 255 حرفاً.',
            'approval_number.max' => 'لا يمكن أن يتجاوز رقم الاعتماد 255 حرفاً.',
            'approval_date.date' => 'صيغة تاريخ الاعتماد غير صحيحة.',
            'financial_effect_note.max' => 'لا يمكن أن يتجاوز بيان الأثر المالي 2000 حرف.',
            'checklist.required' => 'يجب استيفاء متابعة التنفيذ قبل إثبات التنفيذ.',
            'evidence.required' => 'لا يكفي أن تقول الجهة المنفذة (تم التنفيذ)؛ يجب إرفاق دليل التنفيذ.',
            'evidence.min' => 'لا يكفي أن تقول الجهة المنفذة (تم التنفيذ)؛ يجب إرفاق دليل التنفيذ.',
            'evidence.*.attachment_id.required' => 'يجب تحديد مستند دليل التنفيذ.',
            'evidence.*.attachment_id.exists' => 'مستند دليل التنفيذ المحدد غير موجود.',
            'evidence.*.evidence_type.required' => 'يجب تحديد نوع دليل التنفيذ.',
            'evidence.*.evidence_type.in' => 'نوع دليل التنفيذ المحدد غير صالح.',
        ];

        foreach (RequestExecutionService::EXECUTOR_CHECKS as $check) {
            $question = RequestExecutionService::TRACKING_CHECKS[$check];
            $messages["checklist.{$check}.required"] = "يجب الإجابة على: {$question}";
            $messages["checklist.{$check}.in"] = "إجابة غير صالحة على: {$question}";
        }

        return $messages;
    }
}
