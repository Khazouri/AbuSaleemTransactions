<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only validates the shape of `reason` — whether it's actually required
 * depends on the server-computed readiness verdict, which isn't known until
 * the controller calls MeetingReadinessService, so that check lives there.
 */
class ConveneMeetingRequest extends FormRequest
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
}
