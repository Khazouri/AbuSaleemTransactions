<?php

namespace App\Services;

use App\Models\Decision;
use App\Models\Request;
use App\Models\RequestStatusHistory;

/**
 * Stage 79 — [D] Art. 101's twelve notification moments, and Art. 102's limits
 * on what a notice may say.
 *
 * Art. 101 is a statement about STATES, not about call sites: its own preamble
 * is "يتم إشعار الموظف، **بحسب مرحلة المعاملة**، عند" followed by twelve
 * moments, every one of which this system already has a status code for. That
 * is why the trigger is `RequestStatusHistory` (see RequestStatusNoticeObserver)
 * rather than a hook in each of the eight services that write a status — a
 * future writer cannot forget to notify, because the row it must write is the
 * notification trigger.
 *
 * This class owns the mapping only. NotificationDispatcher decides who hears
 * it and RequestNoticeNotification decides how it reads — the same split every
 * other rules class in this codebase keeps.
 */
class EmployeeNoticeService
{
    /**
     * Art. 101's twelve moments, in the article's own order, keyed by a stable
     * code the notification and the UI both read.
     *
     * @var array<string, array{number: int, ar: string, en: string}>
     */
    public const MOMENTS = [
        'received' => ['number' => 1, 'ar' => 'استلام الطلب في المسار الرسمي', 'en' => 'Request received in the official path'],
        'documents_missing' => ['number' => 2, 'ar' => 'وجود نواقص', 'en' => 'Missing documents'],
        'documents_completed' => ['number' => 3, 'ar' => 'اكتمال النواقص', 'en' => 'Missing documents completed'],
        'placed_on_agenda' => ['number' => 4, 'ar' => 'إدراج الطلب بجدول الأعمال', 'en' => 'Placed on the agenda'],
        'committee_result' => ['number' => 5, 'ar' => 'صدور نتيجة اللجنة', 'en' => 'Committee result issued'],
        'referred_for_approval' => ['number' => 6, 'ar' => 'إحالة القرار للاعتماد', 'en' => 'Referred for approval'],
        'final_approval' => ['number' => 7, 'ar' => 'ورود الاعتماد النهائي', 'en' => 'Final approval received'],
        'returned_for_completion' => ['number' => 8, 'ar' => 'إعادة الموضوع للاستكمال', 'en' => 'Returned for completion'],
        'not_approved' => ['number' => 9, 'ar' => 'عدم الموافقة', 'en' => 'Not approved'],
        'no_jurisdiction' => ['number' => 10, 'ar' => 'عدم الاختصاص', 'en' => 'Outside jurisdiction'],
        'execution_started' => ['number' => 11, 'ar' => 'بدء التنفيذ', 'en' => 'Execution started'],
        'closed' => ['number' => 12, 'ar' => 'إقفال المعاملة', 'en' => 'Request closed'],
    ];

    /**
     * The two moments where the employee must actually DO something, and are
     * therefore the only two whose notice carries the recorded reason.
     *
     * النموذج 04 requires the notice to state what is missing ("تبين الحاجة إلى
     * استكمال الآتي"), and Art. 102's own limiting rule admits "الإجراء المطلوب
     * منه". Every other moment withholds the internal text: النموذج 16's عدم
     * الموافقة formula says "للأسباب المثبتة في القرار المعتمد" rather than
     * repeating them, which is Art. 102 in action.
     */
    public const REASON_BEARING_MOMENTS = ['documents_missing', 'returned_for_completion'];

    /**
     * Art. 38's own two نواقص codes — the states whose presence in a request's
     * history turns a later `registered` from moment 1 into moment 3.
     */
    private const INCOMPLETE_STATUSES = ['incomplete', 'completion_required'];

    /**
     * status code => moment, for the moments a bare destination status settles.
     *
     * Statuses deliberately absent, each a decision rather than an omission:
     *   - `execution_suspended` (Art. 105) and `returned_by_approving_body`
     *     (Art. 94) — Art. 101 enumerates twelve and this track's purpose is
     *     identity with [D], not addition; both are internal, unconcluded
     *     matters of exactly the kind Art. 102 excludes, and both resolve back
     *     onto a status that IS a moment, so the employee hears the outcome.
     *   - `reopened_for_representation` and the Track J appeal statuses — not
     *     in Art. 101; the appellant already has Art. 75 pt 6's own
     *     `appeal_decided` notice (Stage 65).
     *   - `executed` (Art. 38 code 19) — Art. 101's eleventh moment is بدء
     *     التنفيذ, not its completion, and moment 12 follows.
     *   - `cancelled` — withdrawal is Appendices 68/69, i.e. Stage 83.
     *   - the pre-committee working statuses (`new`, `in_review`, `ready`,
     *     `routed_to_*`, `nominated_for_committee`, `under_discussion`,
     *     `under_legal_review`, `awaiting_recommendation_approval`,
     *     `in_meeting`) — internal progress Art. 101 does not name. The
     *     existing, muteable `stage_changed` still covers those.
     *
     * @var array<string, string>
     */
    private const STATUS_MOMENTS = [
        'registered' => 'received',
        'incomplete' => 'documents_missing',
        'on_agenda' => 'placed_on_agenda',

        // Art. 26's own deferral, and Stage 35's two "the committee wants
        // something before it decides" outcomes: all three are a نتيجة اللجنة
        // that disposes of nothing yet.
        'deferred' => 'committee_result',
        'legal_opinion_requested' => 'committee_result',
        'referred_to_other_body' => 'committee_result',

        // DecisionController::record() decides and refers in one act, so the
        // approve outcomes land straight on the referral status — which is
        // exactly how النموذج 16 words it, as one notice covering both.
        'awaiting_municipal_approval' => 'referred_for_approval',
        'awaiting_central_approval' => 'referred_for_approval',
        'approved_with_conditions' => 'referred_for_approval',
        'approved' => 'referred_for_approval', // legacy, Stage 69

        'final_approved' => 'final_approval',

        'completion_required' => 'returned_for_completion',
        'returned' => 'returned_for_completion',

        // `rejected` is the pre-committee administrative refusal (Stage 54's
        // reject_formally / Stage 16's reject_review); `not_approved` is the
        // committee's own. Art. 101 asks only that the employee be told the
        // request was not approved, so both are moment 9 with their own wording.
        'not_approved' => 'not_approved',
        'rejected' => 'not_approved',

        'outside_jurisdiction' => 'no_jurisdiction',
        'in_execution' => 'execution_started',

        'completed_closed' => 'closed',
        'archived' => 'closed', // legacy
    ];

    /**
     * The moment a `completion_required` file re-entering the flow represents.
     *
     * `resume_discussion` and the re-review loop both mean the same thing the
     * employee cares about: the completion arrived. Kept as its own map rather
     * than folded into STATUS_MOMENTS because these three statuses mean nothing
     * to the employee arrived at from anywhere else.
     *
     * @var list<string>
     */
    private const COMPLETION_RESUMED_STATUSES = ['under_discussion', 'under_legal_review', 'ready'];

    /**
     * Which of Art. 101's moments, if any, a status change represents.
     *
     * `$fromCode` matters for exactly two decisions, both of which would
     * otherwise be guesses:
     *
     *  - `registered` reached having previously held a نواقص status is not a
     *    second استلام. Art. 20 grants the قيد once (Art. 99, and Stage 70
     *    enforces it by only ever minting a reference number when there is
     *    none), but a file returned on `return_missing_docs` re-walks the chain
     *    and reaches `registered` again — which is literally moment 3, the
     *    completeness re-check passing.
     *  - leaving `completion_required` for a working status is the same news
     *    from the committee's side.
     */
    public function momentFor(Request $requestRecord, ?string $fromCode, string $toCode): ?string
    {
        if ($fromCode === 'completion_required' && in_array($toCode, self::COMPLETION_RESUMED_STATUSES, true)) {
            return 'documents_completed';
        }

        if ($toCode === 'registered' && $this->hasHeldIncompleteStatus($requestRecord)) {
            return 'documents_completed';
        }

        return self::STATUS_MOMENTS[$toCode] ?? null;
    }

    /**
     * The facts a notice may quote, gathered once so the notification class
     * stays a pure renderer of scalars (SystemNotification's own rule).
     *
     * Deliberately narrow, and that narrowness IS Art. 102: the meeting number
     * and the deferral's required completion are both things النموذج 16 itself
     * prints, while the vote tally, the members' names and any other
     * employee's data are simply never read here.
     *
     * @return array{meeting_number: ?string, required_completion: ?string, detail: ?string}
     */
    public function contextFor(Request $requestRecord, string $moment, ?string $reason): array
    {
        $decision = $this->latestDecision($requestRecord);

        return [
            // النموذج 16's موافقة formula: "قد انتهت في اجتماعها رقم ......".
            'meeting_number' => $moment === 'referred_for_approval'
                ? $decision?->meetingRequest?->meeting?->meeting_number
                : null,
            // Its تأجيل formula's "(1) … (2) … (3) …" blanks. Stage 74 records
            // exactly this on the decision (Art. 34's second field), so the
            // blanks are filled from the record instead of left as dots.
            'required_completion' => $moment === 'committee_result'
                ? $decision?->deferral_required_completion
                : null,
            'detail' => in_array($moment, self::REASON_BEARING_MOMENTS, true) ? $reason : null,
        ];
    }

    /** Has this request ever been parked on one of Art. 38's نواقص codes? */
    private function hasHeldIncompleteStatus(Request $requestRecord): bool
    {
        return RequestStatusHistory::query()
            ->where('request_id', $requestRecord->getKey())
            ->whereHas('toStatus', fn ($query) => $query->whereIn('code', self::INCOMPLETE_STATUSES))
            ->exists();
    }

    /**
     * The request's most recent committee decision, if it has one — the same
     * "latest agenda appearance" selection RequestDetailResource's
     * committee_summary already uses, so the notice and the screen cannot
     * describe two different sittings.
     */
    private function latestDecision(Request $requestRecord): ?Decision
    {
        return Decision::query()
            ->with('meetingRequest.meeting:id,meeting_number')
            ->whereHas('meetingRequest', fn ($query) => $query->where('request_id', $requestRecord->getKey()))
            ->latest('id')
            ->first();
    }
}
