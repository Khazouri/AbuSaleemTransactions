<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingRequest;
use Illuminate\Support\Collection;

/**
 * Stage 36 — turns one meeting's data into the structured snapshot
 * `MeetingMinutes::content` stores. Structured (an array of plain values),
 * not prose: the same source of truth AgendaItemDecisionPanel/DecisionResource
 * already established, so the minutes screen renders it generically instead
 * of re-deriving a text description of what happened.
 */
class MeetingMinutesCompiler
{
    /** @return array<string, mixed> */
    public function compile(Meeting $meeting): array
    {
        $meeting->loadMissing([
            'committee.activeMembers',
            'chairman:id,name',
            'rapporteur:id,name',
            'attendees.user:id,name',
            'agendaItems.request:id,reference_number,title',
            'agendaItems.decision.decidedBy:id,name',
            'agendaItems.votes',
            'agendaItems.notes.createdBy:id,name',
            'agendaItems.conflictDeclarations.user:id,name',
        ]);

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
            'attendance' => $this->attendance($meeting),
            'agenda_items' => $meeting->agendaItems->map(fn (MeetingRequest $item) => $this->agendaItem($item))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function attendance(Meeting $meeting): array
    {
        $activeMemberUserIds = $meeting->committee?->activeMembers->pluck('user_id') ?? collect();

        $present = $meeting->attendees->where('attended', true);
        $absent = $meeting->attendees->where('attended', false);

        $quorumRequired = (int) ceil($activeMemberUserIds->count() / 2);
        $quorumPresent = $present->whereIn('user_id', $activeMemberUserIds)->count();

        return [
            'present' => $present->map(fn ($attendee) => ['id' => $attendee->user->id, 'name' => $attendee->user->name])->values()->all(),
            'absent' => $absent->map(fn ($attendee) => ['id' => $attendee->user->id, 'name' => $attendee->user->name])->values()->all(),
            'quorum_required' => $quorumRequired,
            'quorum_present' => $quorumPresent,
            'quorum_met' => $quorumPresent >= $quorumRequired,
        ];
    }

    /** @return array<string, mixed> */
    private function agendaItem(MeetingRequest $item): array
    {
        return [
            'id' => $item->id,
            'agenda_order' => $item->agenda_order,
            'item_type' => $item->item_type,
            'subject' => $item->request?->title ?? $item->subject,
            'reference_number' => $item->request?->reference_number,
            'votes' => $this->voteTally($item),
            'decision' => $item->decision ? [
                'outcome' => $item->decision->outcome,
                'comment' => $item->decision->comment,
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
