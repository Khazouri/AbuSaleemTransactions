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
 *
 * Stage 73 — the quorum is the one number here that is NOT defined in this
 * file any more. It comes from the committee's own بطاقة تعريف اللجنة (or
 * from the rules frozen onto the meeting when it was convened) via
 * `CommitteeVotingRules`, and is reported as null when nothing was ever
 * transcribed, because [D] Appendix 64 forbids supplying one.
 */
class MeetingReadinessService
{
    public function compute(Meeting $meeting): array
    {
        $meeting->loadMissing([
            'committee.activeMembers.user:id,name',
            'attendees.user:id,name',
            'agendaItems.request.attachments:id,request_id',
            'agendaItems.request.latestLegalReview',
            // Stage 83 — Appendix 30's unresolved conflicts, eager-loaded so
            // the readiness check costs one query rather than one per item.
            'agendaItems.request.documentConflicts:id,request_id,resolved_at',
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

        // --- Stage 68: Appendix 7's "هل تمت المراجعة القانونية المطلوبة؟" ---
        // Deliberately NOT folded into a percentage of its own: the agenda
        // gate makes this vacuous for every item inserted after Stage 68, so a
        // permanent "100%" bar would be noise. It is reported only as an
        // exception, and only when a legacy row actually breaches it.
        $itemsMissingLegalReview = $requestItems->reject(
            fn ($item) => $item->request?->latestLegalReview?->permitsAgenda() === true,
        );

        // --- Stage 83: Appendix 30's "فلا تعرض المعاملة قبل معالجة التعارض" --
        // Reported the same way and for the same reason as the legal review
        // above: the agenda gate makes it vacuous for anything inserted after
        // Stage 83, so it exists for rows that predate the gate — and for a
        // conflict raised *after* an item was already on the agenda, which the
        // insertion gate by definition cannot catch.
        $itemsWithDocumentConflict = $requestItems->filter(
            fn ($item) => $item->request !== null
                && $item->request->documentConflicts->whereNull('resolved_at')->isNotEmpty(),
        );

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

        // --- quorum: the committee's own transcribed rule, never an invented
        // one. Stage 73 — [D] Appendix 64: "ولا يجوز للدليل إنشاء نسبة نصاب
        // أو أغلبية من تلقاء نفسه". A committee whose قرار التشكيل has not
        // been recorded has no quorum to check, and that is reported as its
        // own exception rather than filled in with the pre-Stage-73
        // ceil(members / 2).
        $rules = CommitteeVotingRules::forMeeting($meeting);
        $quorumRequired = $rules->quorumRequired($activeMemberUserIds->count());
        $confirmedMemberCount = $activeMemberUserIds
            ->filter(fn ($id) => $attendeesByUserId->get($id)?->invitation_status === 'confirmed')
            ->count();
        $quorumMet = $quorumRequired === null ? null : $confirmedMemberCount >= $quorumRequired;

        $exceptions = [];
        if ($agendaItems->isEmpty()) {
            $exceptions[] = ['code' => 'empty_agenda'];
        }
        // Art. 84 makes "التحقق من صحة انعقاد الاجتماع وفق القواعد القانونية"
        // a precondition of opening the sitting at all, and that cannot be
        // verified without the rule — so an untranscribed committee blocks
        // convening exactly like any other exception does, with Stage 33's
        // R03 override still the escape hatch for a genuine exception.
        if ($quorumRequired === null) {
            $exceptions[] = ['code' => 'committee_rules_not_recorded'];
        } elseif (! $quorumMet) {
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
        // Stage 68 — Appendix 7's own readiness question, "هل تمت المراجعة
        // القانونية المطلوبة؟", asked literally. For anything inserted after
        // Stage 68 this can never fire: MeetingController::addAgendaItem()
        // refuses a request whose latest legal review does not permit
        // presentation. It exists for agenda rows created before that gate
        // did, which Appendix 7's own instruction says to withhold rather
        // than force through ("إذا كان النقص جوهريًا... يوقف إدراجه").
        if ($itemsMissingLegalReview->isNotEmpty()) {
            $exceptions[] = [
                'code' => 'missing_legal_review',
                'item_ids' => $itemsMissingLegalReview->pluck('id')->values(),
            ];
        }
        // Stage 83 — Appendix 30's own prohibition, asked at the sitting.
        if ($itemsWithDocumentConflict->isNotEmpty()) {
            $exceptions[] = [
                'code' => 'unresolved_document_conflict',
                'item_ids' => $itemsWithDocumentConflict->pluck('id')->values(),
            ];
        }
        // Stage 82 — Art. 83 orders the agenda by rule and leaves the chair
        // free to depart from it (rule 5), but Appendix 24 refuses an
        // undocumented departure: "ولا يجوز استخدام الأولوية لتجاوز ترتيب
        // المعاملات دون مبرر إداري موثق". So a sitting cannot open on an
        // out-of-rule agenda with nothing recorded to explain it — either
        // apply the rule, or write down why not.
        if ($agendaItems->isNotEmpty() && blank($meeting->agenda_order_justification)) {
            $ordering = app(AgendaOrderingService::class);

            if (! $ordering->matchesRule($agendaItems, $ordering->profiles($agendaItems))) {
                $exceptions[] = ['code' => 'agenda_order_departs_from_rule'];
            }
        }

        return [
            'file_percentage' => $filePercentage,
            'member_percentage' => $memberPercentage,
            'agenda_percentage' => $agendaPercentage,
            'invitation_percentage' => $invitationPercentage,
            'quorum_required' => $quorumRequired,
            'quorum_confirmed' => $confirmedMemberCount,
            'quorum_met' => $quorumMet,
            // Stage 73 — the rule that produced the numbers above, so the
            // screen can say which text it is applying (or that there is none).
            'quorum_rule' => $rules->toArray(),
            'exceptions' => $exceptions,
            'ready' => $exceptions === [],
            'convened_at' => $meeting->convened_at?->toIso8601String(),
        ];
    }
}
