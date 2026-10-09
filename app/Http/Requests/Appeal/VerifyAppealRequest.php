<?php

namespace App\Http\Requests\Appeal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stage 60 — three of [A] §9 step 2's four admissibility checks are human
 * judgment calls a verifier attests to (صفة المتظلم، القرار محل التظلم،
 * عدم التكرار — deliberately re-verified here rather than re-running Stage
 * 59's intake-time heuristic, per the stage's own text). All three are
 * required together, mirroring RecordJurisdictionTestRequest's
 * all-required-together shape. المواعيد القانونية is computed server-side
 * (App\Services\AppealVerificationService) and is not a client-supplied
 * field at all.
 *
 * `reason` is optional at the schema layer — the controller requires it only
 * when the overall verdict is a fail, since that depends on the computed
 * deadline check too, which this FormRequest cannot see.
 */
class VerifyAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'appellant_standing' => ['required', 'boolean'],
            'valid_target_decision' => ['required', 'boolean'],
            'non_duplication' => ['required', 'boolean'],
            'reason' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'appellant_standing.required' => 'يجب تحديد نتيجة التحقق من صفة المتظلم.',
            'appellant_standing.boolean' => 'قيمة التحقق من صفة المتظلم غير صالحة.',
            'valid_target_decision.required' => 'يجب تحديد نتيجة التحقق من القرار محل التظلم.',
            'valid_target_decision.boolean' => 'قيمة التحقق من القرار محل التظلم غير صالحة.',
            'non_duplication.required' => 'يجب تحديد نتيجة التحقق من عدم التكرار.',
            'non_duplication.boolean' => 'قيمة التحقق من عدم التكرار غير صالحة.',
        ];
    }
}
