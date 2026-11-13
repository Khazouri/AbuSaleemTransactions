<?php

namespace App\Http\Requests\Meeting;

/**
 * High open items (2026-11-13) — a meeting time sent with its offset is kept
 * as that instant. Eloquent formats a parsed date in its own zone, so
 * «10:00+02:00» would be stored as 10:00 in a UTC database and every screen
 * would show the meeting two hours late. Rewriting it as UTC before
 * validation also makes `after:now` compare the right instant. A time with
 * no offset is left alone: it is already read as UTC.
 */
trait StoresMeetingTimesInUtc
{
    protected function prepareForValidation(): void
    {
        foreach (['scheduled_at', 'agenda_deadline'] as $field) {
            $value = $this->input($field);

            if (is_string($value)
                && preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) === 1
                && ($instant = strtotime($value)) !== false) {
                $this->merge([$field => gmdate('Y-m-d H:i:s', $instant)]);
            }
        }
    }
}
