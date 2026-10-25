<?php

namespace App\Services;

use App\Models\Request;
use App\Models\RequestStageLog;
use App\Models\RequestStepReview;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use Illuminate\Validation\ValidationException;

/**
 * Workflow designer — slice 4 (spec §6). A custom step's checklist, as the
 * gate on the outcomes marked `requires_checks`.
 *
 * Answers belong to one ARRIVAL at the step: the latest stage-log row that
 * brought the file here from somewhere else. A self-loop («تعليق») is not an
 * arrival, so it keeps the card; a return and re-arrival is, so the earlier
 * answers become history and the step is checked afresh. Fails closed: no
 * card for this arrival means refused.
 *
 * Read by RequestController::controlGateRefusal(), so the transition endpoint,
 * the hidden button and blocked_transitions give one answer.
 */
class StepChecklistService
{
    public const NOT_RECORDED = 'لم تُستكمل قائمة التحقق في هذه المرحلة بعد.';

    public const UNANSWERED = 'لم يُجب عن بند قائمة التحقق: ';

    public const BLOCKING = 'الإجابة عن «%s» توقف الطلب في هذه المرحلة.';

    public const NO_FILE = 'لم يُرفق المستند الذي يراجعه البند: ';

    public function __construct(private readonly DocumentReview $review) {}

    /** Does taking $action from the file's current stage wait on that stage's checklist? */
    public function gates(Request $requestRecord, string $action): bool
    {
        // No type-specific override as transitionCandidates() applies: custom rules never carry request_type_id.
        return WorkflowTransition::query()
            ->where('from_stage_id', $requestRecord->current_stage_id)
            ->where('action', $action)
            ->where('requires_checks', true)
            ->exists();
    }

    /**
     * The current stage's checklist. Read by id rather than through the
     * currentStage relation, which detailResource() loads without this column.
     *
     * @return list<array<string, mixed>>
     */
    public function items(Request $requestRecord): array
    {
        return WorkflowStage::query()->find($requestRecord->current_stage_id)?->checklist ?? [];
    }

    /**
     * The actions WorkflowService::reopenAtStage()'s callers log. A
     * reopen onto the stage the file already sits on logs from = to, which
     * would otherwise read as a self-loop and hand the new lap the card of the
     * lap that ended in a cancel or a decision — the gate failing open.
     */
    private const REOPEN_ACTIONS = ['reopen', 'appeal_redo', 'approval_return_restudy', 'article_105_restudy', 'rollback_results'];

    public function arrival(Request $requestRecord): ?RequestStageLog
    {
        return $requestRecord->stageLogs()
            ->where('to_stage_id', $requestRecord->current_stage_id)
            ->where(fn ($query) => $query
                ->whereNull('from_stage_id')
                ->orWhere('from_stage_id', '!=', $requestRecord->current_stage_id)
                ->orWhereIn('action', self::REOPEN_ACTIONS))
            ->orderByDesc('acted_at')
            ->orderByDesc('id')
            ->first();
    }

    /** This arrival's card, if one was saved. */
    public function current(Request $requestRecord): ?RequestStepReview
    {
        $arrival = $this->arrival($requestRecord);

        return $arrival === null ? null : RequestStepReview::query()
            ->where('request_id', $requestRecord->id)
            ->where('arrival_log_id', $arrival->id)
            ->first();
    }

    /** Why a checked outcome may not be taken yet, or null. One item at a time, in checklist order. */
    public function refusalReason(Request $requestRecord): ?string
    {
        $answers = $this->current($requestRecord)?->answers;

        if ($answers === null) {
            return self::NOT_RECORDED;
        }

        foreach ($this->items($requestRecord) as $item) {
            if (($reason = $this->itemRefusal($requestRecord, $item, $answers[$item['key']] ?? null)) !== null) {
                return $reason;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $item */
    private function itemRefusal(Request $requestRecord, array $item, mixed $answer): ?string
    {
        $unanswered = self::UNANSWERED.$item['label_ar'];

        return match ($item['type']) {
            'yes_no' => match (true) {
                ! in_array($answer, ['yes', 'no'], true) => $unanswered,
                $answer === $item['blocking_answer'] => sprintf(self::BLOCKING, $item['label_ar']),
                default => null,
            },
            'note' => is_string($answer) && trim($answer) !== '' ? null : $unanswered,
            'attestation' => $answer === true ? null : $unanswered,
            'attachment' => $this->attachmentRefusal($requestRecord, $item, is_array($answer) ? $answer : []),
            default => $unanswered,
        };
    }

    /**
     * The file(s) under this item's document, judged as both document gates
     * judge theirs: the latest upload per document, every one approved.
     *
     * @param  array<string, mixed>  $item
     * @param  array<int|string, string>  $verdicts
     */
    private function attachmentRefusal(Request $requestRecord, array $item, array $verdicts): ?string
    {
        $review = $this->review->only([$item['document_key']]);

        if ($review->attachments($requestRecord)->isEmpty()) {
            return self::NO_FILE.$item['label_ar'];
        }

        return $review->refusal($requestRecord, $verdicts);
    }

    // Workflow designer — slice 4.
    public const INVALID = 'إجابة غير صالحة عن البند: ';

    public const UNKNOWN_ITEM = 'بند لا ينتمي إلى قائمة التحقق في هذه المرحلة.';

    /**
     * Save the card for this arrival. The card is saved whole: each save
     * replaces every answer, so the panel resends all the items it has
     * answered, not just the changed one. Each answer must fit its item's type; an attachment
     * verdict must be for a file under that item's document, and one this
     * reviewer opened in full — the same rule both document gates apply.
     *
     * @param  array<string, mixed>  $answers
     */
    public function record(Request $requestRecord, User $actor, array $answers): RequestStepReview
    {
        $items = collect($this->items($requestRecord))->keyBy('key');
        $errors = [];
        $clean = [];

        if (array_diff_key($answers, $items->all()) !== []) {
            $errors['answers'][] = self::UNKNOWN_ITEM;
        }

        foreach ($items->only(array_keys($answers)) as $key => $item) {
            $value = $answers[$key];
            $message = $item['type'] === 'attachment'
                ? $this->attachmentAnswerError($requestRecord, $item, $value, $actor)
                : ($this->fits($item['type'], $value) ? null : self::INVALID.$item['label_ar']);

            if ($message !== null) {
                $errors["answers.{$key}"][] = $message;

                continue;
            }
            $clean[$key] = $value;
        }

        $arrival = $this->arrival($requestRecord);
        if ($arrival === null) {
            $errors['answers'][] = self::NOT_RECORDED;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return RequestStepReview::query()->updateOrCreate(
            ['request_id' => $requestRecord->id, 'arrival_log_id' => $arrival->id],
            [
                'workflow_stage_id' => $requestRecord->current_stage_id,
                'answers' => $clean,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
            ],
        );
    }

    private function fits(string $type, mixed $value): bool
    {
        return match ($type) {
            'yes_no' => in_array($value, ['yes', 'no'], true),
            'note' => is_string($value) && mb_strlen($value) <= 5000,
            'attestation' => is_bool($value),
            default => false,
        };
    }

    /** @param  array<string, mixed>  $item */
    private function attachmentAnswerError(Request $requestRecord, array $item, mixed $value, User $actor): ?string
    {
        $review = $this->review->only([$item['document_key']]);
        $underReview = $review->attachments($requestRecord)->pluck('id')->all();

        if (! is_array($value)
            || collect($value)->contains(fn ($verdict) => ! in_array($verdict, DocumentReview::ANSWERS, true))
            || array_diff(array_keys($value), $underReview) !== []) {
            return self::INVALID.$item['label_ar'];
        }

        return $review->unpreviewed($requestRecord, $value, $actor);
    }

    /**
     * What the wizard's panel needs. Each verdict rides on its file row: a map
     * keyed by attachment id would reach the SPA re-indexed as a plain list
     * (AGENTS.md Gotchas).
     *
     * @return array<string, mixed>|null
     */
    public function forViewer(Request $requestRecord, ?User $viewer): ?array
    {
        $items = $this->items($requestRecord);
        if ($items === []) {
            return null;
        }

        $card = $this->current($requestRecord);
        $answers = $card?->answers ?? [];

        return [
            'items' => collect($items)->map(fn (array $item) => [
                ...$item,
                'answer' => $item['type'] === 'attachment' ? null : ($answers[$item['key']] ?? null),
                'files' => $item['type'] !== 'attachment' ? [] : collect($this->review->only([$item['document_key']])->forViewer($requestRecord, $viewer))
                    ->map(fn (array $file) => [...$file, 'verdict' => $answers[$item['key']][$file['id']] ?? null])
                    ->all(),
            ])->all(),
            'reviewed_by' => $card?->reviewedBy?->only(['id', 'name']),
            'reviewed_at' => $card?->reviewed_at?->toIso8601String(),
            'refusal' => $this->refusalReason($requestRecord),
        ];
    }
}
