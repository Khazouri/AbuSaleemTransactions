<?php

namespace App\Http\Requests\Request;

use App\Models\RequestSuspension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 78 — what the legal review concluded about an Art. 105 suspension,
 * and therefore where the file goes.
 *
 * Where it goes is deliberately not a separate parameter: the two outcomes
 * carry their own routing (resume where the file was frozen, or back to the
 * committee for إعادة العرض), so a caller cannot pair "the doubt was cleared"
 * with a move to the committee. Stage 77's own reasoning for keeping the
 * resolution's destination out of the payload.
 */
class LiftSuspensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution_action' => ['required', Rule::in(array_keys(RequestSuspension::RESOLUTIONS))],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'resolution_action.required' => 'يرجى تحديد نتيجة المراجعة القانونية.',
            'resolution_action.in' => 'نتيجة المراجعة القانونية غير معروفة.',
            'resolution_note.max' => 'ملاحظة رفع الإيقاف أطول من الحد المسموح به.',
        ];
    }
}
