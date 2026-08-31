<?php

namespace App\Http\Requests\Vote;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Casts (or updates) one committee member's vote on an agenda item. */
class StoreVoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Stage 35 — three richer outcomes alongside the original three;
            // see DecisionController::ACTIONS for what each drives. Stage 41
            // adds `abstain` — a vote value with no matching ACTIONS entry,
            // so it can never drive a workflow transition, only be tallied.
            'vote' => ['required', 'string', Rule::in([
                'approve', 'reject', 'defer',
                'conditional_approval', 'legal_opinion', 'refer_other_body',
                'abstain',
            ])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'vote.required' => 'يجب اختيار التصويت.',
            'vote.in' => 'قيمة التصويت غير صالحة.',
            'comment.max' => 'لا يمكن أن يتجاوز التعليق 2000 حرف.',
        ];
    }
}
