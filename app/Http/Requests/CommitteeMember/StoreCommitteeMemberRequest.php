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
            // Stage 102 — required: the committee is its five Art. 10 (أ)
            // seats, and a member without one is never invited.
            'seat' => [
                'required',
                Rule::in(CommitteeMember::SEATS),
                Rule::unique('committee_members', 'seat')->where('committee_id', $committee->id),
            ],
        ];

        // Stage 102 bound every seat to its role; since 2026-10-02 only the
        // rapporteur seat still is — it is المقرر, the one who schedules and
        // invites (`meetings,add`, R02 alone). Any other seat may go to a
        // stand-in, who carries that seat role's meeting duties while invited
        // (SeatDutyGrant).
        $roleCode = $this->input('seat') === 'rapporteur' ? CommitteeMember::SEAT_ROLES['rapporteur'] : null;
        if ($roleCode !== null) {
            $rules['user_id'][] = Rule::exists('role_user', 'user_id')
                ->where('role_id', Role::query()->where('code', $roleCode)->value('id'));
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'يجب اختيار مستخدم.',
            'user_id.exists' => $this->input('seat') === 'rapporteur'
                ? 'هذا المقعد مقصور على من يحمل دور «'.Role::query()->where('code', CommitteeMember::SEAT_ROLES['rapporteur'])->value('name_ar').'».'
                : 'المستخدم المحدد غير موجود.',
            'user_id.unique' => 'هذا المستخدم عضو بالفعل في اللجنة.',
            'seat.required' => 'يجب تحديد مقعد العضو في اللجنة.',
            'seat.in' => 'المقعد المحدد غير معروف.',
            'seat.unique' => 'هذا المقعد مشغول بالفعل في اللجنة، يجب إزالة شاغله أولاً.',
        ];
    }
}
