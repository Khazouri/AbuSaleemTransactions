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
            // Stage 48 — this committee's own tashkil decision: does its
            // rapporteur get a substantive vote, or only the seat?
            'rapporteur_votes' => $this->rapporteur_votes,

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
