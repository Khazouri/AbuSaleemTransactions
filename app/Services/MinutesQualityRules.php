<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\MeetingRequest;

/**
 * Stage 78 — [D] Appendix 63's بوابة 3 (قبل الاعتماد), which Appendix 8 spells
 * out: "**لا يحال محضر اللجنة للاعتماد قبل التحقق من**" sixteen things.
 *
 * The host is `MeetingMinutesController::review()`'s approve branch — that is
 * the act that ends the محضر's draft life and sends it to signature and
 * اعتماد, so it is the moment Appendix 8 is talking about.
 *
 * Fourteen of the sixteen are derived, one is attested, and one is
 * deliberately not asked at all — the Stage 60/75/76 split (ask a human only
 * what the system cannot answer, because asking and then overriding is worse
 * than not asking).
 *
 * The five snapshot-comparison checks are where this gate earns its keep.
 * `meeting_minutes.content` is frozen at generate() time (Stage 36, and Art.
 * 88's own reason), so a محضر compiled before the agenda changed is a محضر
 * that no longer says what the meeting was — and nothing in this system
 * caught that before: generate → change the agenda → approve was a clean
 * path to an approved document describing a sitting that did not happen.
 * Appendix 63's own gate-3 question is exactly that: "هل المحضر والقرار
 * يعكسان ما انتهت إليه اللجنة؟"
 */
class MinutesQualityRules
{
    /**
     * Appendix 8's list, verbatim and in the appendix's own order.
     *
     * @var array<string, string>
     */
    public const CHECKS = [
        'meeting_number_matches' => 'تطابق رقم الاجتماع',
        'date_correct' => 'صحة التاريخ',
        'member_names_match' => 'تطابق أسماء الأعضاء',
        'attendance_recorded' => 'إثبات الحضور',
        'convening_validity_recorded' => 'إثبات صحة الانعقاد',
        'items_match_agenda' => 'تطابق البنود مع جدول الأعمال',
        'every_item_has_a_result' => 'وجود نتيجة لكل بند نوقش',
        'decisions_separated_from_recommendations' => 'فصل القرارات عن التوصيات',
        'deferrals_clear' => 'وضوح حالات التأجيل',
        'required_completions_named' => 'تحديد النواقص المطلوبة',
        'refusals_clear' => 'وضوح حالات عدم الموافقة',
        'sensitive_results_reasoned' => 'تسبيب النتائج الحساسة',
        'next_approving_body_named' => 'تحديد جهة الاعتماد التالية',
        'request_numbers_match' => 'تطابق أرقام المعاملات',
        'no_internal_contradictions' => 'خلو المحضر من تعارضات داخلية',
        'signatures_complete' => 'استكمال التوقيعات',
    ];

    /**
     * The one check a human answers. "خلو المحضر من تعارضات داخلية" is
     * judgment about prose — no column can hold the answer, and there is
     * nothing to compare against.
     */
    public const REVIEWER_CHECKS = ['no_internal_contradictions'];

    /**
     * Deliberately not asked, and not merely skipped.
     *
     * Stage 36's lifecycle is approve-then-sign: `review()` is what *creates*
     * the signature rows, so at the moment this gate runs there are no
     * signatures yet and an attestation would be asking someone to vouch for
     * something that has not happened. It is also the one check the system
     * already enforces outright — a محضر cannot reach `approved` until every
     * signature row carries a `signed_at` — so the honest answer here is
     * "enforced elsewhere", recorded as such rather than as a tick.
     */
    public const ENFORCED_ELSEWHERE = ['signatures_complete'];

    /**
     * Evaluate the fourteen derived checks against the frozen snapshot and
     * the meeting's current data.
     *
     * @return array<string, bool>
     */
    public function derive(Meeting $meeting, MeetingMinutes $minutes): array
    {
        $meeting->loadMissing([
            'attendees',
            'agendaItems.request:id,reference_number,jurisdiction_test',
            'agendaItems.decision',
        ]);

        $content = is_array($minutes->content) ? $minutes->content : [];
        $snapshotMeeting = $content['meeting'] ?? [];
        $attendance = $content['attendance'] ?? [];
        $items = is_array($content['agenda_items'] ?? null) ? $content['agenda_items'] : [];

        $present = is_array($attendance['present'] ?? null) ? $attendance['present'] : [];
        $absent = is_array($attendance['absent'] ?? null) ? $attendance['absent'] : [];

        $snapshotAttendeeIds = collect([...$present, ...$absent])->pluck('id')->filter()->sort()->values()->all();
        $currentAttendeeIds = $meeting->attendees->pluck('user_id')->sort()->values()->all();

        $snapshotItemIds = collect($items)->pluck('id')->filter()->sort()->values()->all();
        $currentItemIds = $meeting->agendaItems->pluck('id')->sort()->values()->all();

        // Only the items that carry a decision are asked Stage 74's structural
        // questions; an administrative or emerging item has no decision to
        // separate, defer, refuse or reason about (Stage 31).
        $decided = collect($items)->filter(fn ($item) => is_array($item['decision'] ?? null));

        return [
            'meeting_number_matches' => ($snapshotMeeting['meeting_number'] ?? null) === $meeting->meeting_number,
            'date_correct' => ($snapshotMeeting['scheduled_at'] ?? null) === $meeting->scheduled_at?->toIso8601String(),
            'member_names_match' => $snapshotAttendeeIds === $currentAttendeeIds,
            // "إثبات الحضور" and "إثبات صحة الانعقاد" are two separate
            // lines in Appendix 8, so they mean two different things: this
            // one is that attendance was recorded at all (who came and who
            // did not), and the quorum is the next check's job.
            'attendance_recorded' => $present !== [] || $absent !== [],
            // Stage 73 — the committee's own transcribed rule, never an
            // invented ratio. A committee whose قرار التشكيل was never
            // recorded reports null here, and Art. 84 makes verifying صحة
            // الانعقاد a precondition, so null fails.
            'convening_validity_recorded' => ($attendance['quorum_met'] ?? null) === true,
            'items_match_agenda' => $snapshotItemIds === $currentItemIds,
            'every_item_has_a_result' => $meeting->agendaItems->every(fn (MeetingRequest $item) => $item->isResolved()),
            'decisions_separated_from_recommendations' => $decided->every(
                fn ($item) => filled($item['decision']['instrument'] ?? null),
            ),
            'deferrals_clear' => $decided->every(fn ($item) => ($item['decision']['outcome'] ?? null) !== 'defer'
                || filled($item['decision']['deferral']['reason'] ?? null)),
            'required_completions_named' => $decided->every(fn ($item) => ($item['decision']['outcome'] ?? null) !== 'defer'
                || filled($item['decision']['deferral']['required_completion'] ?? null)),
            'refusals_clear' => $decided->every(
                fn ($item) => ! app(DecisionStructureRules::class)->requiresRefusalReason((string) ($item['decision']['outcome'] ?? ''))
                    || filled($item['decision']['refusal_reason_code'] ?? null),
            ),
            'sensitive_results_reasoned' => $decided->every(
                fn ($item) => ! app(DecisionStructureRules::class)->isSubstantive((string) ($item['decision']['outcome'] ?? ''))
                    || (filled($item['decision']['facts'] ?? null) && filled($item['decision']['basis'] ?? null)),
            ),
            // Stage 54's Art. 45 test is the one place this system *requires*
            // an answer to "من الجهة صاحبة الاعتماد النهائي؟"; Stage 68's own
            // `approving_body` is nullable and so cannot carry this check.
            'next_approving_body_named' => $meeting->agendaItems
                ->where('item_type', 'employee_request')
                ->every(fn (MeetingRequest $item) => filled($item->request?->jurisdiction_test['final_approval_authority'] ?? null)),
            'request_numbers_match' => collect($items)
                ->filter(fn ($item) => ($item['item_type'] ?? null) === 'employee_request')
                ->every(function ($item) use ($meeting) {
                    $current = $meeting->agendaItems->firstWhere('id', $item['id'] ?? null);

                    return $current !== null
                        && ($item['reference_number'] ?? null) === $current->request?->reference_number;
                }),
        ];
    }

    /**
     * One Arabic message at a time, in Appendix 8's own order, so a reviewer
     * is told what to fix rather than handed a wall — the discipline
     * `DecisionStructureRules` set.
     *
     * @param  array<string, bool>  $reviewerAnswers
     */
    public function refusalReason(Meeting $meeting, MeetingMinutes $minutes, array $reviewerAnswers): ?string
    {
        $derived = $this->derive($meeting, $minutes);

        foreach (self::CHECKS as $key => $label) {
            if (in_array($key, self::ENFORCED_ELSEWHERE, true)) {
                continue;
            }

            $answer = in_array($key, self::REVIEWER_CHECKS, true)
                ? ($reviewerAnswers[$key] ?? false)
                : ($derived[$key] ?? false);

            if ($answer !== true) {
                return 'لا يحال المحضر للاعتماد قبل التحقق من: '.$label;
            }
        }

        return null;
    }

    /**
     * All sixteen, stored together, so the record reads as Appendix 8's own
     * list. The sixteenth carries its honest value rather than a tick — see
     * ENFORCED_ELSEWHERE.
     *
     * @param  array<string, bool>  $reviewerAnswers
     * @return array<string, string>
     */
    public function record(Meeting $meeting, MeetingMinutes $minutes, array $reviewerAnswers): array
    {
        $derived = $this->derive($meeting, $minutes);
        $record = [];

        foreach (array_keys(self::CHECKS) as $key) {
            $record[$key] = match (true) {
                in_array($key, self::ENFORCED_ELSEWHERE, true) => 'enforced_by_signature_lifecycle',
                in_array($key, self::REVIEWER_CHECKS, true) => ($reviewerAnswers[$key] ?? false) ? 'yes' : 'no',
                default => ($derived[$key] ?? false) ? 'yes' : 'no',
            };
        }

        return $record;
    }
}
