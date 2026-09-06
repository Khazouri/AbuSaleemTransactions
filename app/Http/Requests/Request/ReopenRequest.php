<?php

namespace App\Http\Requests\Request;

use App\Services\ReopenReasonCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 66 — [D] Arts. 34–37/78–79: re-presenting a concluded request needs
 * both an enumerated `reason_code` (never a free-text-only justification —
 * see App\Services\ReopenReasonCatalog) and a `target_stage_id` naming where
 * it re-enters ordinary processing (the same picker Stage 64's
 * `redo-stage-options` already serves). `note` is optional elaboration.
 */
class ReopenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', Rule::in(ReopenReasonCatalog::CODES)],
            'target_stage_id' => ['required', 'integer', Rule::exists('workflow_stages', 'id')],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason_code.required' => 'يجب اختيار سبب إعادة العرض.',
            'reason_code.in' => 'سبب إعادة العرض غير صالح.',
            'target_stage_id.required' => 'يجب تحديد المرحلة التي يعاد عرض الطلب عندها.',
            'target_stage_id.exists' => 'المرحلة المحددة غير صالحة.',
            'note.max' => 'لا يمكن أن يتجاوز التوضيح 5000 حرف.',
        ];
    }
}
