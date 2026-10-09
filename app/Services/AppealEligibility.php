<?php

namespace App\Services;

use App\Models\Appeal;
use App\Models\Decision;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\User;

/**
 * Stage 59, Track J — the one place "may this person file this appeal?"
 * lives, mirroring DecisionEligibility's shape (Stage 25).
 *
 * Three rules, in the order reasonBlockingAppeal reports them:
 *   1. ownership — an appeal may only target a request the appellant filed;
 *   2. the target must actually have "reached a result" — restricted to the
 *      current statuses matching Art. 38 codes 12/13/14/17/19/20, resolved
 *      via Stage 54b's reconciliation table (gap-analysis.md §4), not
 *      re-derived from the status names;
 *   3. non-duplication — a second appeal against the same request requires a
 *      declared new fact ([A] §9 step 2), regardless of the first appeal's
 *      own resolution.
 */
class AppealEligibility
{
    /**
     * Current status codes matching one of Art. 38's 12/13/14/17/19/20.
     * Stage 69 gave code 13 a status of its own (`not_approved`) and split
     * 19 out of `completed_closed`, so the list is now literal rather than
     * approximate; the `cancelled` special case below survives only for rows
     * written before that stage.
     */
    private const QUALIFYING_STATUS_CODES = [
        'decided',                 // 12 موافق عليها من اللجنة
        'approved_with_conditions', // 12, with an added condition
        'outside_jurisdiction',    // 14 عدم اختصاص (exact match)
        'final_approved',          // 17 معتمدة نهائيًا
        'not_approved',            // 13 غير موافق عليها (Stage 69 — exact match)
        'executed',                // 19 منفذة (Stage 69)
        'completed_closed',        // 20 مغلقة ومؤرشفة
        'archived',                // 20, legacy-only
        // `in_execution` (Art. 38 code 18) is deliberately excluded —
        // STAGE_PLAN's own code list skips it while naming 17/19/20 on
        // either side, so this honours that literally rather than guessing.
    ];

    /**
     * Why this appellant may not file this appeal, or null if they may.
     * Returns the message, not a boolean, so a refusal reads as an
     * explanation rather than a dead button — same convention as
     * DecisionEligibility::reasonBlockingVote.
     */
    public function reasonBlockingAppeal(Request $originalRequest, User $appellant, ?string $newFactsDeclaration): ?string
    {
        if ($originalRequest->created_by_user_id !== $appellant->id) {
            return 'لا يجوز تقديم تظلم إلا بخصوص طلب قدّمه المتظلم نفسه.';
        }

        if (! $this->requestReachedResult($originalRequest)) {
            return 'لا يجوز التظلم إلا ضد طلب توصّل إلى نتيجة نهائية.';
        }

        $hasPriorAppeal = Appeal::query()
            ->where('appellant_user_id', $appellant->id)
            ->where('original_request_id', $originalRequest->id)
            ->exists();

        if ($hasPriorAppeal && trim((string) $newFactsDeclaration) === '') {
            return 'سبق تقديم تظلم بشأن هذا الطلب؛ يلزم بيان وقائع جديدة لتقديم تظلم آخر.';
        }

        return null;
    }

    /**
     * Does this request's current status mean it "reached a result" in the
     * Art. 38 sense the intake screen restricts appeal targets to?
     *
     * `cancelled` is a special case: it covers both a plain administrative
     * withdrawal (any open stage's `cancel` exception) and the committee's
     * own `reject` decision outcome (DecisionController's outcome→action map
     * routes it through the same self-loop) — Art. 38 has a code (13) for
     * the latter, not the former, and gap-analysis.md §4 flags this
     * conflation as a known, not-yet-fixed gap in the status vocabulary
     * itself. Rather than silently treat every cancellation as appealable
     * (wrong) or reject all of them (also wrong — it's the only way a
     * committee rejection is representable today), distinguish using data
     * that already exists: a real committee rejection always has a linked
     * Decision with outcome `reject`; a plain withdrawal never does.
     */
    public function requestReachedResult(Request $originalRequest): bool
    {
        $statusCode = $originalRequest->status?->code;

        if (in_array($statusCode, self::QUALIFYING_STATUS_CODES, true)) {
            return true;
        }

        if ($statusCode === 'cancelled') {
            return Decision::query()
                ->whereHas('meetingRequest', fn ($query) => $query->where('request_id', $originalRequest->id))
                ->where('outcome', 'reject')
                ->exists();
        }

        return false;
    }

    /**
     * The Decision behind a request's most recent committee appearance, if
     * any — "القرار المتظلم منه (auto-filled from the pick)". Mirrors the
     * "latest agenda appearance" selection RequestResource::proposed_meeting
     * and RequestDetailResource::committee_summary already use, so the three
     * places agree on what "the current decision" means.
     */
    public function latestDecisionFor(Request $originalRequest): ?Decision
    {
        /** @var MeetingRequest|null $agendaItem */
        $agendaItem = $originalRequest->meetingRequests()
            ->with('decision')
            ->latest('id')
            ->first();

        return $agendaItem?->decision;
    }
}
