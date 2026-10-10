<?php

namespace App\Services;

use App\Models\Request;
use App\Models\RequestStageLog;
use App\Models\RequestStepReview;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;

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

    public function arrival(Request $requestRecord): ?RequestStageLog
    {
        return $requestRecord->stageLogs()
            ->where('to_stage_id', $requestRecord->current_stage_id)
            ->where(fn ($query) => $query
                ->whereNull('from_stage_id')
                ->orWhere('from_stage_id', '!=', $requestRecord->current_stage_id))
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
}
