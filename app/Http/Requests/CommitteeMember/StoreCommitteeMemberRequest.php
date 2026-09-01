<?php

namespace App\Http\Requests\CommitteeMember;

use App\Models\CommitteeMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Adds one user to the {committee} route-bound committee's membership. */
class StoreCommitteeMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $committee = $this->route('committee');

        $rules = [
            'user_id' => [
                'required', 'integer', 'exists:users,id',
                Rule::unique('committee_members', 'user_id')
                    ->where('committee_id', $committee->id),
            ],
            'is_head' => ['sometimes', 'boolean'],
            'seat' => ['sometimes', 'nullable', Rule::in(CommitteeMember::SEATS)],
        ];

        // The unique rule is added only when a seat is actually supplied:
        // Laravel's unique check runs a `WHERE seat IS NULL` query for a
        // null value, which would wrongly reject a second seatless member
        // against the first one already sitting on this committee.
        if ($this->filled('seat')) {
            $rules['seat'][] = Rule::unique('committee_members', 'seat')
                ->where('committee_id', $committee->id);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'يجب اختيار مستخدم.',
            'user_id.exists' => 'المستخدم المحدد غير موجود.',
            'user_id.unique' => 'هذا المستخدم عضو بالفعل في اللجنة.',
            'seat.in' => 'المقعد المحدد غير معروف.',
            'seat.unique' => 'هذا المقعد مشغول بالفعل في اللجنة، يجب إزالة شاغله أولاً.',
        ];
    }
}
