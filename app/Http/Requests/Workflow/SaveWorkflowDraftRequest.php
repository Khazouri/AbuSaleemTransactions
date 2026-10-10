<?php

namespace App\Http\Requests\Workflow;

use App\Models\WorkflowStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Workflow designer — slice 3. The shape of a whole draft graph. What may
 * change (built-in locks, unknown codes, a custom outcome's label and actor)
 * depends on the draft's current rows, so WorkflowDraftWriter checks it.
 * Stages are listed in their new order; that order is the order_no.
 */
class SaveWorkflowDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $role = ['nullable', 'integer', Rule::exists('roles', 'id')];
        $status = ['nullable', 'integer', Rule::exists('request_statuses', 'id')];

        return [
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.code' => ['present', 'nullable', 'string', 'max:50', 'distinct'],
            'stages.*.name_ar' => ['required', 'string', 'max:255'],
            'stages.*.name_en' => ['nullable', 'string', 'max:255'],
            'stages.*.responsible_role_id' => $role,
            'stages.*.target_days_min' => ['nullable', 'integer', 'min:0', 'max:365'],
            'stages.*.target_days_max' => ['nullable', 'integer', 'min:0', 'max:365'],
            // Workflow designer — slice 4 (spec §5 E12, refused at save rather than at publish).
            'stages.*.checklist' => ['nullable', 'array', 'max:30'],
            'stages.*.checklist.*.key' => ['nullable', 'string', 'max:20'],
            'stages.*.checklist.*.type' => ['required', Rule::in(WorkflowStage::CHECK_TYPES)],
            'stages.*.checklist.*.label_ar' => ['required', 'string', 'max:255'],
            'stages.*.checklist.*.label_en' => ['nullable', 'string', 'max:255'],
            'stages.*.checklist.*.blocking_answer' => ['nullable', 'required_if:stages.*.checklist.*.type,yes_no', Rule::in(['yes', 'no'])],
            'stages.*.checklist.*.document_key' => ['nullable', 'required_if:stages.*.checklist.*.type,attachment', 'string', 'max:100'],
            'transitions' => ['present', 'array'],
            'transitions.*.id' => ['present', 'nullable', 'integer', 'distinct'],
            'transitions.*.from' => ['required', 'string'],
            'transitions.*.to' => ['required', 'string'],
            'transitions.*.label_ar' => ['nullable', 'string', 'max:255'],
            'transitions.*.label_en' => ['nullable', 'string', 'max:255'],
            'transitions.*.required_role_id' => $role,
            'transitions.*.set_status_id' => $status,
            'transitions.*.required_status_id' => $status,
            'transitions.*.is_exception' => ['boolean'],
            'transitions.*.requires_comment' => ['boolean'],
            'transitions.*.requires_submitter_manager' => ['boolean'],
            'transitions.*.requires_creator' => ['boolean'],
            'transitions.*.requires_checks' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stages.required' => 'لا يخلو مسار العمل من المراحل.',
            'stages.*.checklist.max' => 'لا تزيد بنود قائمة التحقق على 30 بندًا.',
            'stages.*.checklist.*.type.required' => 'نوع البند مطلوب.',
            'stages.*.checklist.*.type.in' => 'نوع البند غير معروف.',
            'stages.*.checklist.*.label_ar.required' => 'نص البند بالعربية مطلوب.',
            'stages.*.checklist.*.blocking_answer.required_if' => 'حدد الإجابة التي توقف الطلب.',
            'stages.*.checklist.*.blocking_answer.in' => 'الإجابة المانعة إما «نعم» أو «لا».',
            'stages.*.checklist.*.document_key.required_if' => 'اختر المستند الذي يراجعه هذا البند.',
            'stages.*.code.distinct' => 'رمز المرحلة مكرر.',
            'stages.*.name_ar.required' => 'اسم المرحلة بالعربية مطلوب.',
            'stages.*.responsible_role_id.exists' => 'الدور غير معروف.',
            'transitions.*.id.distinct' => 'النتيجة مكررة.',
            'transitions.*.from.required' => 'مرحلة البداية مطلوبة.',
            'transitions.*.to.required' => 'المرحلة التي تنقل إليها النتيجة مطلوبة.',
            'transitions.*.required_role_id.exists' => 'الدور غير معروف.',
            'transitions.*.set_status_id.exists' => 'الحالة غير معروفة.',
            'transitions.*.required_status_id.exists' => 'الحالة غير معروفة.',
        ];
    }
}
