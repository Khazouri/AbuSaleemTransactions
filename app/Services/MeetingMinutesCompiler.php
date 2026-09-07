<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\CommitteeMember;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingRequest;
use App\Models\Vote;
use Illuminate\Support\Collection;

/**
 * Stage 36 — turns one meeting's data into the structured snapshot
 * `MeetingMinutes::content` stores. Structured (an array of plain values),
 * not prose: the same source of truth AgendaItemDecisionPanel/DecisionResource
 * already established, so the minutes screen renders it generically instead
 * of re-deriving a text description of what happened.
 *
 * Stage 50 — closes the gaps [D] Art. 28's minimum-content list exposed:
 * attendee seat/head roles, per-item facts summary + legal basis (both read
 * from the item's own presentation memo, Stage 46, when one exists — never
 * fabricated), a documents-reviewed list, dissenting votes with their
 * reason, a referral-authority field, and a required-signatories roster.
 * See this stage's AGENT_NOTES entry for why signatures are a roster here,
 * not a live embed: `content` freezes at generate() time, which is only
 * ever reachable while status=draft, and MeetingMinuteSignature rows are
 * only created later inside review()'s approve branch — a compiled
 * snapshot can never coexist with real signature rows, so embedding
 * signed_at/image data here would always read "nobody has signed yet."
 * Actual signed proof stays exactly where it already lives and is already
 * exposed: MeetingMinutes::signatures via MeetingMinutesResource.
 *
 * Stage 73 — the محضر now records the committee's بطاقة تعريف اللجنة and the
 * quorum rule the sitting was measured against, because Appendix 8 lists
 * "إثبات صحة الانعقاد" among the checks a محضر must satisfy before it goes
 * for approval, and validity cannot be shown without naming the rule. The
 * rule comes from the meeting's own convene-time snapshot where one exists,
 * so regenerating a draft cannot silently re-judge a sitting under rules
 * adopted after it was held; a meeting held before Stage 73 has neither a
 * snapshot nor a card and honestly reports nulls rather than the invented
 * ceil(members / 2) it would once have printed.
 */
class MeetingMinutesCompiler
{
    /** @return array<string, mixed> */
    public function compile(Meeting $meeting): array
    {
        $meeting->loadMissing([
            'committee.activeMembers',
            'committee.members',
            'chairman:id,name',
            'rapporteur:id,name',
            'attendees.user:id,name',
            'agendaItems.request.attachments',
            'agendaItems.decision.decidedBy:id,name',
            'agendaItems.votes.user:id,name',
            'agendaItems.notes.createdBy:id,name',
            'agendaItems.conflictDeclarations.user:id,name',
            'agendaItems.presentationMemo',
        ]);

        $membersByUserId = $meeting->committee?->members->keyBy('user_id') ?? collect();

        return [
            'meeting' => [
                'title' => $meeting->title,
                'meeting_number' => $meeting->meeting_number,
                'meeting_type' => $meeting->meeting_type,
                'scheduled_at' => $meeting->scheduled_at?->toIso8601String(),
                'location' => $meeting->location,
                'chairman' => $meeting->chairman ? ['id' => $meeting->chairman->id, 'name' => $meeting->chairman->name] : null,
                'rapporteur' => $meeting->rapporteur ? ['id' => $meeting->rapporteur->id, 'name' => $meeting->rapporteur->name] : null,
            ],
            // Appendix 65's بطاقة تعريف اللجنة, carried into the محضر so the
            // record names the body that sat and the text it sat under.
            'committee' => $this->committeeCard($meeting),
            'attendance' => $this->attendance($meeting, $membersByUserId),
            'agenda_items' => $meeting->agendaItems->map(fn (MeetingRequest $item) => $this->agendaItem($item))->all(),
            // Art. 28 — "توقيعات من يلزم توقيعهم": who is required to sign,
            // the same roster MeetingMinutesController::review() computes
            // when it actually creates the signature rows.
            'required_signatories' => $meeting->attendees->where('attended', true)
                ->map(fn (MeetingAttendee $attendee) => $this->attendeeInfo($attendee, $membersByUserId))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function committeeCard(Meeting $meeting): ?array
    {
        $committee = $meeting->committee;

        if (! $committee) {
            return null;
        }

        return [
            'name_ar' => $committee->name_ar,
            'name_en' => $committee->name_en,
            'formation_decision_number' => $committee->formation_decision_number,
            'formation_decision_date' => $committee->formation_decision_date?->toDateString(),
            'term_note' => $committee->term_note,
            'legal_basis' => $committee->legal_basis,
            'minutes_approval_body' => $committee->minutes_approval_body,
            'minutes_signature_rule' => $committee->minutes_signature_rule,
        ];
    }

    /**
     * @param  Collection<int, CommitteeMember>  $membersByUserId
     * @return array<string, mixed>
     */
    private function attendance(Meeting $meeting, Collection $membersByUserId): array
    {
        $activeMemberUserIds = $meeting->committee?->activeMembers->pluck('user_id') ?? collect();

        $present = $meeting->attendees->where('attended', true);
        $absent = $meeting->attendees->where('attended', false);

        $rules = CommitteeVotingRules::forMeeting($meeting);
        $quorumRequired = $rules->quorumRequired($activeMemberUserIds->count());
        $quorumPresent = $present->whereIn('user_id', $activeMemberUserIds)->count();

        return [
            'present' => $present->map(fn (MeetingAttendee $attendee) => $this->attendeeInfo($attendee, $membersByUserId))->values()->all(),
            'absent' => $absent->map(fn (MeetingAttendee $attendee) => $this->attendeeInfo($attendee, $membersByUserId))->values()->all(),
            'quorum_required' => $quorumRequired,
            'quorum_present' => $quorumPresent,
            'quorum_met' => $quorumRequired === null ? null : $quorumPresent >= $quorumRequired,
            // The rule itself, printed beside the count it produced — an
            // unrecorded rule reads as unrecorded, not as "no quorum needed".
            'quorum_rule' => $rules->toArray(),
        ];
    }

    /**
     * @param  Collection<int, CommitteeMember>  $membersByUserId
     * @return array<string, mixed>
     */
    private function attendeeInfo(MeetingAttendee $attendee, Collection $membersByUserId): array
    {
        /** @var CommitteeMember|null $member */
        $member = $membersByUserId->get($attendee->user_id);

        return [
            'id' => $attendee->user->id,
            'name' => $attendee->user->name,
            // Stage 45's fixed 5-seat roster, when this attendee holds one —
            // Art. 28's "أسماء الحاضرين" is read in every official minutes
            // sample alongside each attendee's capacity/seat.
            'seat' => $member?->seat,
            'is_head' => (bool) $member?->is_head,
        ];
    }

    /** @return array<string, mixed> */
    private function agendaItem(MeetingRequest $item): array
    {
        $memo = $item->presentationMemo?->content['authored'] ?? null;

        return [
            'id' => $item->id,
            'agenda_order' => $item->agenda_order,
            'item_type' => $item->item_type,
            'subject' => $item->request?->title ?? $item->subject,
            'reference_number' => $item->request?->reference_number,
            // Art. 28 — "ملخص الموضوع" / legal basis, both read from this
            // item's own presentation memo (Stage 46) when one was ever
            // generated — never fabricated when it wasn't.
            'facts_summary' => $memo['facts_summary'] ?? null,
            'legal_basis' => $memo['legal_opinion'] ?? null,
            'documents_reviewed' => $item->request?->attachments->map(fn (Attachment $attachment) => [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
                'label' => $attachment->label,
            ])->values()->all() ?? [],
            'votes' => $this->voteTally($item),
            'dissenting_opinions' => $this->dissentingOpinions($item),
            'decision' => $item->decision ? [
                // Stage 70 — Art. 89: "يخصص داخل المحضر لكل معاملة قرار أو نتيجة
                // مستقلة" whose first listed element is رقم القرار, so the
                // number has to be part of the frozen snapshot, not only of
                // the live decisions row.
                'decision_number' => $item->decision->decision_number,
                'outcome' => $item->decision->outcome,
                'comment' => $item->decision->comment,
                'referral_authority' => $item->decision->referral_authority,
                'decided_by' => $item->decision->decidedBy?->name,
                'decided_at' => $item->decision->decided_at?->toIso8601String(),
            ] : null,
            'discussion_notes' => $item->notes->map(fn ($note) => [
                'user' => $note->createdBy?->name,
                'note' => $note->note,
                'created_at' => $note->created_at?->toIso8601String(),
            ])->all(),
            // Stage 48 — [D] Art. 11/15/18: a conflict of interest must be
            // disclosed before discussion and recorded in the minutes.
            'conflict_declarations' => $item->conflictDeclarations->map(fn ($declaration) => [
                'user' => $declaration->user?->name,
                'reason' => $declaration->reason,
                'declared_at' => $declaration->declared_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * Art. 28 — "أي تحفظات يوجب النظام إثباتها": a member who voted against
     * the decided outcome and left a reason. A vote matching the outcome
     * isn't a dissent regardless of whether it carries a comment; an item
     * with no recorded decision yet has nothing to dissent from.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dissentingOpinions(MeetingRequest $item): array
    {
        $outcome = $item->decision?->outcome;
        if ($outcome === null) {
            return [];
        }

        return $item->votes
            ->filter(fn (Vote $vote) => $vote->vote !== $outcome && filled($vote->comment))
            ->map(fn (Vote $vote) => [
                'user' => $vote->user?->name,
                'vote' => $vote->vote,
                'comment' => $vote->comment,
            ])
            ->values()
            ->all();
    }

    /**
     * The decision's own snapshot counts once decided (the authoritative
     * tally); the live vote counts otherwise, for an item still in progress
     * when minutes are generated.
     *
     * @return array<string, int>
     */
    private function voteTally(MeetingRequest $item): array
    {
        if ($item->decision) {
            return [
                'approve' => $item->decision->votes_approve_count,
                'reject' => $item->decision->votes_reject_count,
                'defer' => $item->decision->votes_defer_count,
                'conditional_approval' => $item->decision->votes_conditional_approval_count,
                'legal_opinion' => $item->decision->votes_legal_opinion_count,
                'refer_other_body' => $item->decision->votes_refer_other_body_count,
                // Stage 49 — a seventh outcome.
                'no_jurisdiction' => $item->decision->votes_no_jurisdiction_count,
                // Stage 41 — tallied like any other vote, never an outcome.
                'abstain' => $item->decision->votes_abstain_count,
            ];
        }

        /** @var Collection $counts */
        $counts = $item->votes->countBy('vote');

        return [
            'approve' => $counts->get('approve', 0),
            'reject' => $counts->get('reject', 0),
            'defer' => $counts->get('defer', 0),
            'conditional_approval' => $counts->get('conditional_approval', 0),
            'legal_opinion' => $counts->get('legal_opinion', 0),
            'refer_other_body' => $counts->get('refer_other_body', 0),
            'no_jurisdiction' => $counts->get('no_jurisdiction', 0),
            'abstain' => $counts->get('abstain', 0),
        ];
    }
}
