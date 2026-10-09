<?php

namespace App\Http\Requests\Appeal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 64 — executes Stage 63's already-recorded appeal outcome. Only a
 * format-level check lives here: whether `redo_stage_id` is actually
 * required (only when the confirmed outcome is `appeal_redo`) depends on a
 * lookup the FormRequest can't perform on its own, resolved in
 * AppealController::executeOutcome — the same split StoreDecisionRequest's
 * own docblock already documents for comment requiredness.
 */
class ExecuteAppealOutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'redo_stage_id' => ['nullable', 'integer', Rule::exists('workflow_stages', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'redo_stage_id.exists' => 'المرحلة المحددة غير صالحة.',
        ];
    }
}
