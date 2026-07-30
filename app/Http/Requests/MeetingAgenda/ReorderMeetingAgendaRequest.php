<?php

namespace App\Http\Requests\MeetingAgenda;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reorders the {meeting} route-bound meeting's whole agenda in one request.
 *
 * `order` must be exactly the meeting's current agenda item ids, just
 * permuted — not a subset (that would silently orphan the missing rows from
 * the visible order) and not a superset (that would reference agenda items
 * belonging to a different meeting).
 */
class ReorderMeetingAgendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $meeting = $this->route('meeting');
        $currentIds = $meeting->agendaItems()->pluck('id')->sort()->values()->all();

        return [
            'order' => [
                'required', 'array',
                function (string $attribute, $value, $fail) use ($currentIds) {
                    $submitted = collect($value)->map(fn ($id) => (int) $id)->sort()->values()->all();

                    if ($submitted !== $currentIds) {
                        $fail('يجب أن تتضمن قائمة الترتيب كل عناصر جدول الأعمال الحالية بدون نقص أو زيادة.');
                    }
                },
            ],
            'order.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'order.required' => 'يجب إرسال ترتيب جدول الأعمال.',
        ];
    }
}
