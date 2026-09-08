<?php

namespace App\Services;

use App\Models\Decision;
use App\Models\MeetingRequest;
use App\Models\Request;

/**
 * Stage 78 — [D] Art. 103 (الثانية)'s قائمة فحص سلامة القرار.
 *
 * The article puts its own moment in its first line — "**قبل إحالة النتيجة
 * للتنفيذ** يتم التحقق من" — which in this system is the
 * `final_approval_archiving → in_execution` hop, the R07 self-loop that hands
 * an approved result to execution.
 *
 * Art. 104 is why eight of the twelve are derived rather than ticked: it says
 * in so many words that "**صحة المستند والاختصاص ليستا إجراءات شكلية، بل
 * عنصران جوهريان في سلامة القرار**", and lists غش أو تدليس، جهة غير مختصة and
 * معلومات وبيانات غير صحيحة among the grounds on which a decision is void. A
 * checklist whose every line is an attestation would be exactly the شكلية
 * the article rules out.
 *
 * The remaining four are attested and tri-state (`yes|no|not_applicable`, for
 * Stage 75's reasons). Two of them — صحة اسم الموظف and الرقم الوظيفي — have
 * no employment data to read at all: Track K's scope decision (1) puts ملف
 * الخدمة outside this application, so the honest answer is a human's, and
 * inventing a proxy would report a guess as a recorded fact.
 *
 * It owns the rules only; `WorkflowService` remains the sole mover, and the
 * gate that reads this is repeated at all three sites that must agree — the
 * DecisionStructureRules/CommitteeVotingRules split, and the same dual-path
 * discipline Stages 54, 70 and 77 each had to apply.
 */
class ExecutionSoundnessService
{
    /** The one hop that refers a result to execution, named once. */
    public const GATED_STAGE = 'final_approval_archiving';

    public const GATED_ACTION = 'approve';

    public const ANSWERS = ['yes', 'no', 'not_applicable'];

    /**
     * Art. 103's twelve, verbatim and in the article's own order.
     *
     * @var array<string, string>
     */
    public const CHECKS = [
        'employee_name_correct' => 'صحة اسم الموظف',
        'employee_number_correct' => 'الرقم الوظيفي',
        'decision_subject_present' => 'موضوع القرار',
        'committee_jurisdiction' => 'اختصاص اللجنة',
        'convening_valid' => 'صحة انعقاد الاجتماع',
        'voting_complete' => 'استكمال التصويت',
        'documents_present' => 'وجود المستندات',
        'legal_basis_sound' => 'صحة السند القانوني',
        'minutes_signed' => 'توقيع المحضر',
        'competent_authority_approved' => 'اعتماد السلطة المختصة',
        'effective_date_correct' => 'صحة تاريخ النفاذ',
        'no_conflict_with_attachments' => 'عدم وجود تعارض بين منطوق القرار والمرفقات',
    ];

    /**
     * The four the certifying officer answers.
     *
     * صحة اسم الموظف and الرقم الوظيفي have no employment record to check
     * against (Track K scope decision (1)); صحة تاريخ النفاذ is Stage 76's
     * execution card and is genuinely not known yet at this moment — the file
     * is being *referred* to execution, not executed; and عدم وجود تعارض بين
     * منطوق القرار والمرفقات is judgment about the fit between prose and
     * documents.
     */
    public const CERTIFIER_CHECKS = [
        'employee_name_correct',
        'employee_number_correct',
        'effective_date_correct',
        'no_conflict_with_attachments',
    ];

    /**
     * The eight Art. 104 insists cannot be شكلية, evaluated against real
     * state.
     *
     * @return array<string, bool>
     */
    public function derive(Request $requestRecord): array
    {
        $item = $this->decidingAgendaItem($requestRecord);
        $decision = $item?->decision;
        $meeting = $item?->meeting;
        $minutes = $meeting?->meetingMinutes;

        $jurisdiction = is_array($requestRecord->jurisdiction_test) ? $requestRecord->jurisdiction_test : [];

        return [
            'decision_subject_present' => filled($decision?->decision_subject),
            // Stage 54's Art. 45 test, and specifically its البلدية question —
            // Art. 104 names "جهة غير مختصة" as a ground of nullity.
            'committee_jurisdiction' => ($jurisdiction['within_municipal_jurisdiction'] ?? null) == true,
            // Stage 73's recorded quorum rule, frozen onto the meeting when it
            // was convened. Null (an untranscribed committee) fails, per Art.
            // 84 and Appendix 64.
            'convening_valid' => $this->convenedValidly($item),
            'voting_complete' => $decision !== null && $this->voteTotal($decision) > 0,
            'documents_present' => $requestRecord->attachments()->exists(),
            // Stage 74's Appendix 27 السند — the decision's own legal basis.
            'legal_basis_sound' => filled($decision?->decision_basis),
            'minutes_signed' => $minutes?->status === 'approved',
            // The origin status of this very hop, plus Stage 77's rule that a
            // file the approving body sent back is not approved onward until
            // the re-processing action has been recorded.
            'competent_authority_approved' => $requestRecord->status?->code === 'final_approved'
                && ! $requestRecord->openApprovalReturn()->exists(),
        ];
    }

    /**
     * One Arabic message at a time, in Art. 103's own order.
     *
     * @param  array<string, string>|null  $answers  the certifier's answers, when one is being submitted
     */
    public function refusalReason(Request $requestRecord, ?array $answers = null): ?string
    {
        if ($answers === null) {
            $record = $requestRecord->execution_soundness;

            if (! is_array($record)) {
                return 'لا تحال النتيجة للتنفيذ قبل استيفاء قائمة فحص سلامة القرار (المادة 103).';
            }

            $answers = $record;
        }

        $derived = $this->derive($requestRecord);

        foreach (self::CHECKS as $key => $label) {
            if (in_array($key, self::CERTIFIER_CHECKS, true)) {
                if (($answers[$key] ?? null) === 'no') {
                    return 'لا تحال النتيجة للتنفيذ قبل التحقق من: '.$label;
                }

                continue;
            }

            if (($derived[$key] ?? false) !== true) {
                return 'لا تحال النتيجة للتنفيذ قبل التحقق من: '.$label;
            }
        }

        return null;
    }

    /**
     * All twelve stored together — the certifier's four plus the derived
     * eight — so the record reads as Art. 103's own list rather than a subset
     * a reader has to reassemble. Stage 75/76's `record()` precedent.
     *
     * @param  array<string, string>  $answers
     * @return array<string, string>
     */
    public function record(Request $requestRecord, array $answers): array
    {
        $derived = $this->derive($requestRecord);
        $record = [];

        foreach (array_keys(self::CHECKS) as $key) {
            $record[$key] = in_array($key, self::CERTIFIER_CHECKS, true)
                ? $answers[$key]
                : (($derived[$key] ?? false) ? 'yes' : 'no');
        }

        return $record;
    }

    /**
     * The agenda appearance that produced the decision now heading for
     * execution — the latest one, the same "latest by id" selection
     * `RequestDetailResource::committee_summary` and Stage 58's
     * `AppealEligibility` already use, so all three agree on what "the
     * current decision" means.
     */
    public function decidingAgendaItem(Request $requestRecord): ?MeetingRequest
    {
        return $requestRecord->meetingRequests()
            ->whereHas('decision')
            ->with(['decision', 'meeting.meetingMinutes:id,meeting_id,status'])
            ->orderByDesc('id')
            ->first();
    }

    private function convenedValidly(?MeetingRequest $item): bool
    {
        $meeting = $item?->meeting;

        if ($meeting === null) {
            return false;
        }

        $rules = CommitteeVotingRules::forMeeting($meeting);
        $activeMembers = $meeting->committee?->activeMembers()->count() ?? 0;
        $required = $rules->quorumRequired($activeMembers);

        if ($required === null) {
            return false;
        }

        return $meeting->attendees()->where('attended', true)->count() >= $required;
    }

    private function voteTotal(Decision $decision): int
    {
        $total = 0;

        foreach ($decision->getAttributes() as $column => $value) {
            if (str_starts_with($column, 'votes_') && str_ends_with($column, '_count')) {
                $total += (int) $value;
            }
        }

        return $total;
    }
}
