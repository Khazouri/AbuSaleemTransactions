<?php

namespace App\Services;

use App\Models\Meeting;

/**
 * Stage 33 — the pre-meeting readiness gate. Computes the four percentages,
 * the expected-quorum check, and the exceptions list the readiness screen
 * and the convene action both read — see the Stage 33 plan in AGENT_NOTES.md
 * for why each percentage is defined the way it is; there is no design-doc
 * spec beyond the four names, so this is the one place those definitions
 * live rather than being re-derived per caller.
 *
 * `ready` is exactly "the exceptions list is empty" — every exception is a
 * convening blocker, there is no separate blocking/non-blocking split.
 */
class MeetingReadinessService
{
    public function compute(Meeting $meeting): array
    {
        $meeting->loadMissing([
            'committee.activeMembers.user:id,name',
            'attendees.user:id,name',
            'agendaItems.request.attachments:id,request_id',
        ]);

        $activeMemberUserIds = $meeting->committee->activeMembers->pluck('user_id');
        $attendeesByUserId = $meeting->attendees->keyBy('user_id');

        $agendaItems = $meeting->agendaItems;
        $requestItems = $agendaItems->where('item_type', 'employee_request');

        // --- file % --------------------------------------------------------
        $itemsMissingFiles = $requestItems->reject(
            fn ($item) => $item->request && $item->request->attachments->isNotEmpty(),
        );
        $filePercentage = $requestItems->isEmpty()
            ? 100
            : (int) round((($requestItems->count() - $itemsMissingFiles->count()) / $requestItems->count()) * 100);
        $missingFileItemIds = $itemsMissingFiles->pluck('id')->values();

        // --- member % (roster coverage, not attendance) --------------------
        $invitedMemberUserIds = $activeMemberUserIds->filter(fn ($id) => $attendeesByUserId->has($id));
        $memberPercentage = $activeMemberUserIds->isEmpty()
            ? 100
            : (int) round(($invitedMemberUserIds->count() / $activeMemberUserIds->count()) * 100);
        $uninvitedMemberUserIds = $activeMemberUserIds->diff($invitedMemberUserIds)->values();

        // --- agenda % (fully briefed: priority + estimated time set) -------
        $briefedItems = $agendaItems->filter(
            fn ($item) => $item->priority !== null && $item->estimated_minutes !== null,
        );
        $agendaPercentage = $agendaItems->isEmpty()
            ? 0
            : (int) round(($briefedItems->count() / $agendaItems->count()) * 100);

        // --- invitations % (an actual answer, not just "invited") ----------
        $responded = $meeting->attendees->whereIn('invitation_status', ['confirmed', 'declined']);
        $invitationPercentage = $meeting->attendees->isEmpty()
            ? 100
            : (int) round(($responded->count() / $meeting->attendees->count()) * 100);

        // --- quorum: simple majority of current active members -------------
        $quorumRequired = (int) ceil($activeMemberUserIds->count() / 2);
        $confirmedMemberCount = $activeMemberUserIds
            ->filter(fn ($id) => $attendeesByUserId->get($id)?->invitation_status === 'confirmed')
            ->count();
        $quorumMet = $confirmedMemberCount >= $quorumRequired;

        $exceptions = [];
        if ($agendaItems->isEmpty()) {
            $exceptions[] = ['code' => 'empty_agenda'];
        }
        if (! $quorumMet) {
            $exceptions[] = ['code' => 'quorum_not_met', 'required' => $quorumRequired, 'confirmed' => $confirmedMemberCount];
        }
        if ($filePercentage < 100) {
            $exceptions[] = ['code' => 'missing_files', 'item_ids' => $missingFileItemIds];
        }
        if ($memberPercentage < 100) {
            $exceptions[] = ['code' => 'unconfirmed_members', 'user_ids' => $uninvitedMemberUserIds];
        }
        if ($agendaPercentage < 100) {
            $exceptions[] = ['code' => 'incomplete_agenda_items'];
        }
        if ($invitationPercentage < 100) {
            $exceptions[] = ['code' => 'pending_invitations'];
        }

        return [
            'file_percentage' => $filePercentage,
            'member_percentage' => $memberPercentage,
            'agenda_percentage' => $agendaPercentage,
            'invitation_percentage' => $invitationPercentage,
            'quorum_required' => $quorumRequired,
            'quorum_confirmed' => $confirmedMemberCount,
            'quorum_met' => $quorumMet,
            'exceptions' => $exceptions,
            'ready' => $exceptions === [],
            'convened_at' => $meeting->convened_at?->toIso8601String(),
        ];
    }
}
