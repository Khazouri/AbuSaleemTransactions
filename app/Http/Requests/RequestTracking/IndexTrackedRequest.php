<?php

namespace App\Http\Requests\RequestTracking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 89 — the two questions «متابعة طلباتي» actually asks of the list.
 *
 * Deliberately narrower than Request\IndexRequest, which the internal work
 * queue uses: department, request type and the 39 status codes are internal
 * framing, and an employee filtering their own handful of files needs a search
 * box and a coarse open/concluded split, not a status picker.
 */
class IndexTrackedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Matches the tracking number or the subject, the same two columns
            // RequestController::index()'s own `search` already looks at — the
            // param has existed since Stage 20 with no UI exposing it.
            'search' => ['nullable', 'string', 'max:255'],
            'scope' => ['nullable', Rule::in(['open', 'concluded', 'all'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'scope.in' => 'نطاق المتابعة المحدد غير صالح.',
        ];
    }
}
