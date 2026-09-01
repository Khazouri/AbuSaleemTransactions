<?php

namespace App\Http\Requests\ConflictOfInterest;

use Illuminate\Foundation\Http\FormRequest;

/** Stage 48 — a committee member discloses (and, by disclosing, recuses from) one agenda item. */
class StoreConflictOfInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.max' => 'لا يمكن أن يتجاوز بيان تعارض المصالح 2000 حرف.',
        ];
    }
}
