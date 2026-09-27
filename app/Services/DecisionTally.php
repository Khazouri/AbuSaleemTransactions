<?php

namespace App\Services;

use App\Http\Controllers\Api\DecisionController;
use App\Models\MeetingRequest;
use App\Models\Vote;
use Illuminate\Support\Collection;

/**
 * Decision wizard — sub-project 2. What recording an agenda item's decision
 * would produce right now, or why it cannot be recorded yet. Moved out of
 * DecisionController so the endpoint's refusal and the wizard's "blocked"
 * line are one predicate: record()/recordAppealDecision() refuse with it and
 * MeetingDuties::forItem() reports it, with the outcome it would record.
 *
 * Only the checks that need nothing from the recorder live here; the drafting
 * rules (DecisionStructureRules) depend on what they write.
 */
class DecisionTally
{
    /**
     * @return array{outcome: string|null, refusal: string|null, tally: Collection<string, int>, abstain: int}
     */
    public function resolve(MeetingRequest $agendaItem): array
    {
        $isAppeal = $agendaItem->item_type === 'appeal';
        $refuse = fn (string $message) => ['outcome' => null, 'refusal' => $message, 'tally' => collect(), 'abstain' => 0];

        // Stage 31 — an admin/emerging item has no request for
        // WorkflowService::transition() to move.
        if (! $isAppeal && $agendaItem->item_type !== 'employee_request') {
            return $refuse('لا يمكن تسجيل قرار على بند غير مرتبط بطلب.');
        }

        if ($agendaItem->decision()->exists()) {
            return $refuse('تم تسجيل قرار هذا البند بالفعل.');
        }

        // Stage 63 — defensive, not redundant: MeetingController::addAgendaItem()
        // already gates nomination on legal_review, but a second
        // meeting_requests row for the same appeal could otherwise try to
        // re-decide an appeal that already moved past this status.
        if ($isAppeal && $agendaItem->appeal?->status?->code !== 'legal_review') {
            return $refuse('لا يمكن تسجيل قرار على تظلم لم يجتز المراجعة القانونية بعد.');
        }

        // Stage 82 — Art. 85's ninth step (إثبات النتيجة) is recording, and
        // the article puts the other eight before it. Re-checked rather than
        // trusting that existing votes imply it.
        if ($agendaItem->study_sequence_completed_at === null) {
            return $refuse(DecisionEligibility::INCOMPLETE_STUDY_SEQUENCE);
        }

        $counts = Vote::query()
            ->where('meeting_request_id', $agendaItem->id)
            ->selectRaw('vote, count(*) as total')
            ->groupBy('vote')
            ->pluck('total', 'vote');

        $tally = collect($isAppeal ? DecisionController::APPEAL_OUTCOMES : array_keys(DecisionController::ACTIONS))
            ->mapWithKeys(fn (string $outcome) => [$outcome => (int) ($counts[$outcome] ?? 0)]);

        // Stage 41 — tallied like any other vote, but never a candidate for
        // the plurality: it has no outcome, so it stays out of $tally.
        $abstain = (int) ($counts['abstain'] ?? 0);

        [$outcome, $refusal] = $this->resolveOutcome($agendaItem, $tally, $abstain);

        return ['outcome' => $outcome, 'refusal' => $refusal, 'tally' => $tally, 'abstain' => $abstain];
    }

    /**
     * Stage 73 — resolve a tally into the single outcome the committee's own
     * voting rule says it produced, or a refusal explaining why it produced
     * none. Both the ordinary and the appeal tally go through this one
     * method, since they are the same committee voting in the same sitting
     * under the same قرار التشكيل.
     *
     * With no rule transcribed the pre-Stage-73 behaviour stands: the outcome
     * with the most votes wins and a tie is refused. That is deliberate and
     * is not the thing [D] Appendix 64 forbids — plurality asserts no نسبة
     * أغلبية of its own, whereas the quorum figure this stage removed did.
     * Once a real threshold IS recorded it binds, and an outcome that leads
     * without reaching it is refused rather than written down, per Art. 87's
     * "لا يجوز إثبات نتيجة مغايرة لما انتهى إليه التصويت الفعلي".
     *
     * @param  Collection<string, int>  $tally
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveOutcome(MeetingRequest $agendaItem, Collection $tally, int $abstainCount): array
    {
        $max = $tally->max();
        if ($max === 0) {
            return [null, 'لا توجد أصوات مسجلة على هذا البند بعد.'];
        }

        $agendaItem->loadMissing(['meeting.committee', 'meeting.attendees']);
        $rules = CommitteeVotingRules::forMeeting($agendaItem->meeting);

        $leaders = $tally->filter(fn (int $count) => $count === $max);

        if ($leaders->count() > 1) {
            // Art. 87 applies ترجيح صوت الرئيس only where the text or the
            // قرار التشكيل provides for it; otherwise a tie simply leaves the
            // matter undecided and the chair can call another vote.
            if ($rules->tieBreak() !== 'chair_casting_vote') {
                return [null, 'التصويت متعادل، لا يمكن حسم القرار تلقائياً.'];
            }

            $chairOutcome = $this->chairVote($agendaItem);
            if ($chairOutcome === null || ! $leaders->has($chairOutcome)) {
                return [null, 'التصويت متعادل ولم يرجّح صوت رئيس اللجنة أياً من النتائج المتساوية.'];
            }

            $outcome = $chairOutcome;
        } else {
            $outcome = $leaders->keys()->first();
        }

        if ($rules->hasMajorityThreshold()) {
            $base = match ($rules->majorityBasis()) {
                'present' => $agendaItem->meeting?->attendees->where('attended', true)->count() ?? 0,
                'members' => $agendaItem->meeting?->committee?->activeMembers()->count() ?? 0,
                // votes_cast — every recorded vote, abstentions included,
                // matching Appendix 26's register, which counts الممتنعون as
                // votes cast alongside الموافقون and غير الموافقين.
                default => $tally->sum() + $abstainCount,
            };

            $threshold = $rules->majorityThreshold($base);
            if ($threshold !== null && $tally[$outcome] < $threshold) {
                return [null, "لم تبلغ نتيجة التصويت الأغلبية اللازمة وفق بطاقة تعريف اللجنة ({$tally[$outcome]} من {$threshold})."];
            }
        }

        return [$outcome, null];
    }

    /**
     * How the sitting's president voted, for a قرار تشكيل that gives them a
     * casting vote — the meeting's own chairman where one was named, else
     * the committee's standing head seat.
     */
    private function chairVote(MeetingRequest $agendaItem): ?string
    {
        $chairUserId = $agendaItem->meeting?->chairman_user_id
            ?? $agendaItem->meeting?->committee?->members()->where('is_head', true)->value('user_id');

        if ($chairUserId === null) {
            return null;
        }

        return Vote::query()
            ->where('meeting_request_id', $agendaItem->id)
            ->where('user_id', $chairUserId)
            ->value('vote');
    }
}
