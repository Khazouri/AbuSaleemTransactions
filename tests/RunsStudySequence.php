<?php

namespace Tests;

use App\Models\MeetingRequest;
use App\Models\User;
use App\Services\StudySequenceRules;

/**
 * Stage 82 — [D] Art. 85's per-item study sequence, for the many tests whose
 * subject is something else entirely (the vote tally, the workflow move, the
 * محضر, an appeal's own status machine) but which now have to get past
 * DecisionEligibility's Art. 85 check to reach it.
 *
 * Written directly rather than through the endpoint because the callers are
 * mid-walk through a different story; StudySequenceTest exercises the endpoint,
 * its ordering rule and its refusals. Same split, and same reason, as Stage
 * 74's RecordsStructuredDecisions and Stage 78's PassesControlGates.
 */
trait RunsStudySequence
{
    /**
     * Mark every binding step of Art. 85's sequence for this item, so voting
     * on it is open.
     */
    protected function completeStudySequence(MeetingRequest $agendaItem, ?User $actor = null): MeetingRequest
    {
        $rules = app(StudySequenceRules::class);
        $actorId = $actor?->id ?? $agendaItem->meeting?->created_by_user_id;

        $sequence = [];

        foreach ($rules->bindingSteps($agendaItem) as $code) {
            $sequence[$code] = [
                'marked_at' => now()->toIso8601String(),
                'marked_by_user_id' => $actorId,
            ];
        }

        $agendaItem->forceFill([
            'study_sequence' => $sequence,
            'study_sequence_completed_at' => now(),
        ])->save();

        // A study sequence is run at a sitting, and DecisionEligibility refuses
        // a vote on a meeting that was never convened.
        if ($agendaItem->meeting && $agendaItem->meeting->convened_at === null) {
            $agendaItem->meeting->forceFill(['convened_at' => now()])->save();
        }

        return $agendaItem->refresh();
    }
}
