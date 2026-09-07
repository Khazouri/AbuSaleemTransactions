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

            // Stage 73 — [D] Appendix 65's بطاقة تعريف اللجنة. The quorum and
            // majority halves are what MeetingReadinessService,
            // MeetingMinutesCompiler and DecisionController actually apply;
            // nulls mean the قرار التشكيل has not been transcribed, which
            // Appendix 64 says the system must report rather than fill in.
            'formation_decision_number' => $this->formation_decision_number,
            'formation_decision_date' => $this->formation_decision_date?->toDateString(),
            'term_note' => $this->term_note,
            'legal_basis' => $this->legal_basis,
            'minutes_approval_body' => $this->minutes_approval_body,
            'voting_rights_note' => $this->voting_rights_note,
            'minutes_signature_rule' => $this->minutes_signature_rule,
            'recusal_rules' => $this->recusal_rules,
            'quorum_type' => $this->quorum_type,
            'quorum_count' => $this->quorum_count,
            'quorum_numerator' => $this->quorum_numerator,
            'quorum_denominator' => $this->quorum_denominator,
            'quorum_comparator' => $this->quorum_comparator,
            'quorum_text' => $this->quorum_text,
            'majority_type' => $this->majority_type,
            'majority_basis' => $this->majority_basis,
            'majority_numerator' => $this->majority_numerator,
            'majority_denominator' => $this->majority_denominator,
            'majority_comparator' => $this->majority_comparator,
            'majority_text' => $this->majority_text,
            'tie_break' => $this->tie_break,
            'tie_break_text' => $this->tie_break_text,
            // Whether the rules above are complete enough to actually apply —
            // the one flag the committee list needs to flag an unconfigured
            // committee without re-deriving the rule per row.
            'rules_recorded' => $this->resource->votingRules()->hasQuorumRule(),

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
