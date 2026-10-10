<?php

namespace App\Http\Requests\Workflow;

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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stages.required' => 'لا يخلو مسار العمل من المراحل.',
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
