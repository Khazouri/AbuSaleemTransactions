<?php

namespace App\Http\Requests\Request;

use App\Services\ExecutionSoundnessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 78 — [D] Art. 103's قائمة فحص سلامة القرار, the four answers a human
 * actually supplies.
 *
 * The other eight are derived from real state and are deliberately not
 * accepted here at all — Art. 104's "صحة المستند والاختصاص ليستا إجراءات
 * شكلية" is exactly the reason, and it is also why a spoofed value for one of
 * them cannot reach the record: `ExecutionSoundnessService::record()` reads
 * them from the database, not from this payload.
 *
 * Tri-state for Stage 75's reasons — الرقم الوظيفي has no employment record to
 * check against in this application at all (Track K scope decision (1)), so
 * "لا ينطبق" is an honest answer rather than a loophole; a `no` refuses.
 */
class RecordExecutionSoundnessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['checks' => ['required', 'array']];

        foreach (ExecutionSoundnessService::CERTIFIER_CHECKS as $check) {
            $rules["checks.{$check}"] = ['required', Rule::in(ExecutionSoundnessService::ANSWERS)];
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [
            'checks.required' => 'يجب إرسال إجابات قائمة فحص سلامة القرار.',
            'checks.array' => 'صيغة قائمة فحص سلامة القرار غير صالحة.',
        ];

        foreach (ExecutionSoundnessService::CERTIFIER_CHECKS as $check) {
            $label = ExecutionSoundnessService::CHECKS[$check];
            $messages["checks.{$check}.required"] = 'يرجى الإجابة عن: '.$label;
            $messages["checks.{$check}.in"] = 'إجابة غير صالحة عن: '.$label;
        }

        return $messages;
    }
}
