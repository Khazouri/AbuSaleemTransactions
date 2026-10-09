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
        // Stage 63 — the {agendaItem} route model is already resolved by the
        // time rules() runs (same pattern StoreMeetingAgendaRequest uses for
        // {meeting}), so the allowed vote vocabulary can be picked per the
        // item's own type rather than accepting either vocabulary on either
        // kind of item.
        $agendaItem = $this->route('agendaItem');

        // Stage 35 — three richer outcomes alongside the original three; see
        // DecisionController::ACTIONS for what each drives. Stage 49 adds
        // `no_jurisdiction`, a fourth self-loop outcome.
        $employeeRequestOutcomes = [
            'approve', 'reject', 'defer',
            'conditional_approval', 'legal_opinion', 'refer_other_body', 'no_jurisdiction',
        ];
        // Stage 63 — Art. 75 point 5's five-outcome appeal vocabulary; see
        // DecisionController::APPEAL_OUTCOMES.
        $appealOutcomes = [
            'appeal_accept', 'appeal_partial_accept', 'appeal_reject', 'appeal_refer', 'appeal_redo',
        ];

        // Stage 41 — `abstain` has no matching ACTIONS/APPEAL_OUTCOMES entry,
        // so it can never drive a decision, only be tallied.
        $allowed = $agendaItem?->item_type === 'appeal'
            ? [...$appealOutcomes, 'abstain']
            : [...$employeeRequestOutcomes, 'abstain'];

        return [
            'vote' => ['required', 'string', Rule::in($allowed)],
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
