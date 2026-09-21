<?php

namespace App\Http\Requests\CommitteeMember;

use App\Models\CommitteeMember;
use App\Models\Role;
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
            // Deliberately NOT unique per committee: this endpoint sets a
            // person's membership rather than only creating one, and the
            // controller upserts on (committee, user). It has to, now that
            // CommitteeController::store() seats the creator automatically —
            // otherwise that person could never afterwards be given a named
            // seat on the committee they just formed. One row per person is
            // still guaranteed, by the upsert rather than by a refusal.
            'user_id' => ['required', 'integer', 'exists:users,id'],
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

        // Stage 99 — [D] Appendix 6 rows 9–11 name العضو القانوني, and R11 is
        // that role. Stage 45 left the `legal` seat role-free, so the seat and
        // the role could name two different people; the seat now requires it.
        if ($this->input('seat') === 'legal') {
            $rules['user_id'][] = Rule::exists('role_user', 'user_id')
                ->where('role_id', Role::query()->where('code', 'R11')->value('id'));
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'يجب اختيار مستخدم.',
            'user_id.exists' => $this->input('seat') === 'legal'
                ? 'مقعد العضو القانوني مقصور على من يحمل دور العضو القانوني.'
                : 'المستخدم المحدد غير موجود.',
            'user_id.unique' => 'هذا المستخدم عضو بالفعل في اللجنة.',
            'seat.in' => 'المقعد المحدد غير معروف.',
            'seat.unique' => 'هذا المقعد مشغول بالفعل في اللجنة، يجب إزالة شاغله أولاً.',
        ];
    }
}
