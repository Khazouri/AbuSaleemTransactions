<?php

namespace App\Http\Resources;

use App\Models\CommitteeMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class CommitteeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'description' => $this->description,
            'is_active' => $this->is_active,

            // Stage 73 — [D] Appendix 65's بطاقة تعريف اللجنة.
            'formation_decision_number' => $this->formation_decision_number,
            'formation_decision_date' => $this->formation_decision_date?->toDateString(),
            'term_note' => $this->term_note,
            'legal_basis' => $this->legal_basis,

            // Counts explain why a delete might be blocked (meetings held),
            // same reasoning as DepartmentResource's users_count/children_count.
            'members_count' => $this->whenCounted('members'),
            'meetings_count' => $this->whenCounted('meetings'),

            'members' => CommitteeMemberResource::collection($this->whenLoaded('members')),

            // Stage 45 — [D] Art. 10's 5 named seats, individually addressable
            // regardless of how many other unseated members this committee
            // also carries.
            'seats' => $this->when(
                $this->relationLoaded('members'),
                fn () => $this->seatMap(),
            ),
        ];
    }

    private function seatMap(): Collection
    {
        $bySeat = $this->members->keyBy('seat');

        return collect(CommitteeMember::SEATS)->mapWithKeys(function (string $seat) use ($bySeat) {
            $member = $bySeat->get($seat);

            return [$seat => $member ? [
                'member_id' => $member->id,
                'user' => [
                    'id' => $member->user->id,
                    'name' => $member->user->name,
                ],
            ] : null];
        });
    }
}
