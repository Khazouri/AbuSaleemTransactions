<?php

namespace App\Http\Requests\MeetingAgenda;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 34 — the live runner advancing (or reopening) one agenda item's
 * state. Shape-only: whether `complete` is actually reachable for this item
 * (a request item needs a recorded decision instead) is a business rule, not
 * a validation rule, so it's enforced in MeetingController::updateItemState.
 */
class UpdateMeetingAgendaItemStateRequest extends FormRequest
{
    public const STATES = ['presented', 'discussion', 'voting', 'deciding', 'complete'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_state' => ['required', Rule::in(self::STATES)],
        ];
    }

    public function messages(): array
    {
        return [
            'item_state.required' => 'حالة البند مطلوبة.',
            'item_state.in' => 'حالة البند غير صالحة.',
        ];
    }
}
