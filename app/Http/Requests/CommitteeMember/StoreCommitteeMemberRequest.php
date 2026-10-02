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

        return [
            // Deliberately NOT unique per committee: this endpoint sets a
            // person's membership rather than only creating one, and the
            // controller upserts on (committee, user). It has to, now that
            // CommitteeController::store() seats the creator automatically —
            // otherwise that person could never afterwards be given a named
            // seat on the committee they just formed. One row per person is
            // still guaranteed, by the upsert rather than by a refusal.
            'user_id' => ['required', 'integer', 'exists:users,id'],
            // Stage 102 — required: the committee is its five Art. 10 (أ)
            // seats, and a member without one is never invited. The
            // rapporteur seat is not offered: it is always the system's one
            // مقرر, seated by Committee::seatRapporteur() (user decision
            // 2026-10-02). Any other seat may go to a stand-in, who carries
            // that seat role's meeting duties while invited (SeatDutyGrant).
            'seat' => [
                'required',
                Rule::in(array_diff(CommitteeMember::SEATS, ['rapporteur'])),
                Rule::unique('committee_members', 'seat')->where('committee_id', $committee->id),
            ],
        ];

    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'يجب اختيار مستخدم.',
            'user_id.exists' => 'المستخدم المحدد غير موجود.',
            'user_id.unique' => 'هذا المستخدم عضو بالفعل في اللجنة.',
            'seat.required' => 'يجب تحديد مقعد العضو في اللجنة.',
            'seat.in' => $this->input('seat') === 'rapporteur'
                ? 'مقعد المقرر يشغله مقرر النظام تلقائيًا.'
                : 'المقعد المحدد غير معروف.',
            'seat.unique' => 'هذا المقعد مشغول بالفعل في اللجنة، يجب إزالة شاغله أولاً.',
        ];
    }
}
