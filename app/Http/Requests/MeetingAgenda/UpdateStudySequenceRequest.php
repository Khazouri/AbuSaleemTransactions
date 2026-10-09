<?php

namespace App\Http\Requests\MeetingAgenda;

use App\Services\StudySequenceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 82 — one tick on النموذج 11's card, i.e. one step of [D] Art. 85's
 * per-item sequence.
 *
 * Shape only: whether this particular step may be marked *now* depends on
 * which steps precede it and on whether voting has begun, both of which are
 * business rules — see StudySequenceRules::firstProblemMarking().
 */
class UpdateStudySequenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'step' => ['required', Rule::in(StudySequenceRules::attestedSteps())],
            'done' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'step.required' => 'يجب تحديد خطوة الدراسة.',
            'step.in' => 'خطوة الدراسة غير صالحة.',
            'done.required' => 'يجب تحديد ما إذا كانت الخطوة قد تمت.',
        ];
    }
}
