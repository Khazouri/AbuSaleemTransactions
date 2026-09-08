<?php

namespace App\Services;

use App\Models\MeetingRequest;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Stage 82 — [D] Art. 85's per-item study sequence, as النموذج 11's card.
 *
 * Three of [D]'s own texts describe the same thing at three grains, and only
 * one of them is the vocabulary:
 *
 *  - **Art. 85** gives the sequence itself, nine steps: "عرض الموضوع ← عرض
 *    ملخص الوقائع ← بيان المستندات ← عرض الرأي القانوني عند وجوده ← المناقشة
 *    ← طلب الإيضاحات ← إقفال المناقشة ← التصويت ← إثبات النتيجة", introduced
 *    with "يتبع في كل بند التسلسل الآتي" — a sequence, not a set.
 *  - **النموذج 11** is the card the rapporteur fills during the sitting: the
 *    same nine minus its own header (عرض الموضوع is what the card's header
 *    block *is*) and minus its own result field (إثبات النتيجة is the
 *    نتيجة التصويت line), i.e. seven ticks.
 *  - **Appendix 25** groups them into five إلزامية stages (تعريف الموضوع /
 *    عرض الوقائع / عرض السند / المناقشة / النتيجة) and adds the rule that
 *    closes the last one: "ولا يجوز استمرار تعديل الوقائع أو المستندات بعد
 *    بدء التصويت".
 *
 * So: one nine-step vocabulary, each step carrying the Appendix 25 stage it
 * belongs to, and النموذج 11's card is the subset a human actually ticks.
 *
 * **Two steps are derived, never attested.** التصويت is "votes exist on this
 * item" and إثبات النتيجة is "a decision exists" — both are facts the system
 * already holds, and asking a human to tick them would invite an answer that
 * contradicts the record. Same split every gate since Stage 60 has used.
 *
 * **One step is conditional** — عرض الرأي القانوني carries Art. 85's own
 * qualifier "عند وجوده", so it binds only when the item actually has a legal
 * opinion to present (Stage 68's `request_legal_reviews` for a request item,
 * Stage 62's `appeals.legal_review` for an appeal one) and is not applicable
 * otherwise.
 *
 * **One step is optional** — طلب الإيضاحات is a thing that may or may not
 * have happened, which is exactly how النموذج 11 lists it.
 *
 * The remaining five bind, and they bind *in order*: a step cannot be marked
 * until every binding step before it is. That is what stops the card becoming
 * a row of ticks applied in one go after the fact.
 */
class StudySequenceRules
{
    public const MODE_REQUIRED = 'required';

    public const MODE_CONDITIONAL = 'conditional';

    public const MODE_OPTIONAL = 'optional';

    public const MODE_DERIVED = 'derived';

    /**
     * Art. 85's nine steps in the article's own order, each with the Appendix
     * 25 stage it falls under.
     *
     * @var array<string, array{ar: string, en: string, stage: int, mode: string}>
     */
    public const STEPS = [
        'subject_presented' => [
            'ar' => 'عرض الموضوع',
            'en' => 'Subject presented',
            'stage' => 1,
            'mode' => self::MODE_REQUIRED,
        ],
        'facts_presented' => [
            'ar' => 'عرض ملخص الوقائع',
            'en' => 'Summary of facts presented',
            'stage' => 2,
            'mode' => self::MODE_REQUIRED,
        ],
        'documents_reviewed' => [
            'ar' => 'بيان المستندات',
            'en' => 'Documents stated',
            'stage' => 2,
            'mode' => self::MODE_REQUIRED,
        ],
        'legal_opinion_presented' => [
            'ar' => 'عرض الرأي القانوني عند وجوده',
            'en' => 'Legal opinion presented, where one exists',
            'stage' => 3,
            'mode' => self::MODE_CONDITIONAL,
        ],
        'discussion_held' => [
            'ar' => 'المناقشة',
            'en' => 'Discussion',
            'stage' => 4,
            'mode' => self::MODE_REQUIRED,
        ],
        'clarifications_requested' => [
            'ar' => 'طلب الإيضاحات',
            'en' => 'Clarifications requested',
            'stage' => 4,
            'mode' => self::MODE_OPTIONAL,
        ],
        'discussion_closed' => [
            'ar' => 'إقفال المناقشة',
            'en' => 'Discussion closed',
            'stage' => 5,
            'mode' => self::MODE_REQUIRED,
        ],
        'vote_taken' => [
            'ar' => 'التصويت',
            'en' => 'Vote taken',
            'stage' => 5,
            'mode' => self::MODE_DERIVED,
        ],
        'result_recorded' => [
            'ar' => 'إثبات النتيجة',
            'en' => 'Result recorded',
            'stage' => 5,
            'mode' => self::MODE_DERIVED,
        ],
    ];

    /** Appendix 25's five إلزامية stages, in its own order. */
    public const STAGES = [
        1 => ['ar' => 'تعريف الموضوع', 'en' => 'Identifying the matter'],
        2 => ['ar' => 'عرض الوقائع', 'en' => 'Presenting the facts'],
        3 => ['ar' => 'عرض السند', 'en' => 'Presenting the legal basis'],
        4 => ['ar' => 'المناقشة', 'en' => 'Discussion'],
        5 => ['ar' => 'النتيجة', 'en' => 'The result'],
    ];

    /** The steps a human ticks — everything that is not derived. */
    public static function attestedSteps(): array
    {
        return array_keys(array_filter(
            self::STEPS,
            fn (array $step) => $step['mode'] !== self::MODE_DERIVED,
        ));
    }

    /**
     * Whether this item has a legal opinion to present at all — Art. 85's
     * "عند وجوده". A request item reads Stage 68's own record; an appeal item
     * reads Track J's (Stage 62); an administrative or emerging item has
     * neither, so the step simply does not apply.
     */
    public function hasLegalOpinion(MeetingRequest $agendaItem): bool
    {
        if ($agendaItem->item_type === 'employee_request') {
            return $agendaItem->request?->latestLegalReview !== null;
        }

        if ($agendaItem->item_type === 'appeal') {
            return ! empty($agendaItem->appeal?->legal_review);
        }

        return false;
    }

    /**
     * The attested steps this item must carry before its result may be
     * recorded, in Art. 85's order.
     *
     * @return list<string>
     */
    public function bindingSteps(MeetingRequest $agendaItem): array
    {
        $hasLegalOpinion = $this->hasLegalOpinion($agendaItem);
        $binding = [];

        foreach (self::STEPS as $code => $definition) {
            $binds = match ($definition['mode']) {
                self::MODE_REQUIRED => true,
                self::MODE_CONDITIONAL => $hasLegalOpinion,
                default => false,
            };

            if ($binds) {
                $binding[] = $code;
            }
        }

        return $binding;
    }

    /** Which attested steps have been marked, oldest record wins. */
    private function marked(MeetingRequest $agendaItem): array
    {
        return is_array($agendaItem->study_sequence) ? $agendaItem->study_sequence : [];
    }

    public function isComplete(MeetingRequest $agendaItem): bool
    {
        $marked = $this->marked($agendaItem);

        foreach ($this->bindingSteps($agendaItem) as $code) {
            if (! isset($marked[$code])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Why this step may not be marked (or unmarked) right now, or null.
     *
     * Two rules, both from the sources rather than from taste: a step may not
     * be marked before the binding steps that precede it in Art. 85's own
     * order, and nothing may be unmarked once voting has begun — Appendix 25
     * closes its fifth stage with "ولا يجوز استمرار تعديل الوقائع أو
     * المستندات بعد بدء التصويت", and un-ticking إقفال المناقشة mid-vote is
     * exactly the kind of after-the-fact change it rules out.
     */
    public function firstProblemMarking(MeetingRequest $agendaItem, string $step, bool $done): ?string
    {
        $definition = self::STEPS[$step] ?? null;

        if ($definition === null || $definition['mode'] === self::MODE_DERIVED) {
            return 'هذه الخطوة تثبت تلقائياً من واقع التصويت والقرار، ولا تسجل يدوياً.';
        }

        if ($definition['mode'] === self::MODE_CONDITIONAL && ! $this->hasLegalOpinion($agendaItem)) {
            return 'لا يوجد رأي قانوني مسجل على هذا البند، فلا محل لإثبات عرضه.';
        }

        if ($agendaItem->decision()->exists()) {
            return 'تم تسجيل قرار هذا البند بالفعل، ولا يجوز تعديل تسلسل دراسته بعده.';
        }

        if (! $done && $agendaItem->votes()->exists()) {
            return 'بدأ التصويت على هذا البند، ولا يجوز التراجع عن خطوات دراسته بعد بدئه.';
        }

        if ($done) {
            $marked = $this->marked($agendaItem);

            foreach ($this->bindingSteps($agendaItem) as $code) {
                if ($code === $step) {
                    break;
                }

                if (! isset($marked[$code])) {
                    return 'يجب اتباع تسلسل دراسة البند: لم تثبت بعد خطوة «'.self::STEPS[$code]['ar'].'».';
                }
            }
        }

        return null;
    }

    /**
     * Mark or unmark one step, keeping `study_sequence_completed_at` in step
     * with the JSON beside it — see the migration for why that column exists.
     */
    public function mark(MeetingRequest $agendaItem, string $step, bool $done, User $actor): MeetingRequest
    {
        $sequence = $this->marked($agendaItem);

        if ($done) {
            $sequence[$step] = [
                'marked_at' => Carbon::now()->toIso8601String(),
                'marked_by_user_id' => $actor->id,
            ];
        } else {
            unset($sequence[$step]);
        }

        $agendaItem->study_sequence = $sequence;
        $agendaItem->study_sequence_completed_at = $this->isComplete($agendaItem) ? Carbon::now() : null;
        $agendaItem->save();

        return $agendaItem;
    }

    /**
     * النموذج 11's card for one item: every step with its Appendix 25 stage,
     * whether it applies, and — for the two derived ones — the fact the
     * system already holds rather than an answer anybody typed.
     */
    public function card(MeetingRequest $agendaItem): array
    {
        $marked = $this->marked($agendaItem);
        $hasLegalOpinion = $this->hasLegalOpinion($agendaItem);
        $hasVotes = $agendaItem->votes()->exists();
        $hasDecision = $agendaItem->decision()->exists();

        $steps = [];

        foreach (self::STEPS as $code => $definition) {
            $applicable = $definition['mode'] !== self::MODE_CONDITIONAL || $hasLegalOpinion;

            $done = match ($code) {
                'vote_taken' => $hasVotes,
                'result_recorded' => $hasDecision,
                default => isset($marked[$code]),
            };

            $steps[] = [
                'code' => $code,
                'name_ar' => $definition['ar'],
                'name_en' => $definition['en'],
                'stage' => $definition['stage'],
                'stage_name_ar' => self::STAGES[$definition['stage']]['ar'],
                'stage_name_en' => self::STAGES[$definition['stage']]['en'],
                'mode' => $definition['mode'],
                'applicable' => $applicable,
                'done' => $done,
                'marked_at' => $marked[$code]['marked_at'] ?? null,
                'marked_by_user_id' => $marked[$code]['marked_by_user_id'] ?? null,
            ];
        }

        return [
            'steps' => $steps,
            'is_complete' => $this->isComplete($agendaItem),
            'completed_at' => $agendaItem->study_sequence_completed_at?->toIso8601String(),
            // Appendix 25's own closing rule, surfaced so the screen can say
            // why the memo and the attachments went read-only.
            'material_frozen' => $hasVotes && ! $hasDecision,
        ];
    }
}
