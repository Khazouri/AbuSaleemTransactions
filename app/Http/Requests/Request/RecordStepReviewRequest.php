<?php

namespace App\Http\Requests\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Workflow designer — slice 4. A custom step's checklist answers, keyed by
 * item key. Format only: what each item accepts depends on the step's own
 * checklist, so StepChecklistService::record() checks the values.
 */
class RecordStepReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['answers' => ['present', 'array']];
    }

    public function messages(): array
    {
        return [
            'answers.present' => 'يجب إرسال إجابات قائمة التحقق.',
            'answers.array' => 'صيغة إجابات قائمة التحقق غير صالحة.',
        ];
    }
}
