<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One decided/request agenda item followed through its live downstream state. */
class MeetingOutputResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $requestRecord = $this->request;
        $statusCode = $requestRecord?->status?->code;
        $stage = $requestRecord?->currentStage;
        $inExecution = $statusCode === 'in_execution';
        // Stage 69 — Art. 38's code 19 (منفذة) is its own state between 18 and
        // 20: the effect has been applied but the file is not closed until the
        // documentation has been verified (Appendix 5).
        $executed = $statusCode === 'executed';
        $closed = in_array($statusCode, ['completed_closed', 'archived'], true);
        $atFinalStage = $stage?->code === 'final_approval_archiving';

        return [
            'agenda_item_id' => $this->id,
            'agenda_order' => $this->agenda_order,
            'request' => $requestRecord ? [
                'id' => $requestRecord->id,
                'reference_number' => $requestRecord->reference_number,
                'title' => $requestRecord->title,
                'employee' => $requestRecord->createdBy ? [
                    'id' => $requestRecord->createdBy->id,
                    'name' => $requestRecord->createdBy->name,
                ] : null,
                'department' => $this->namedEntity($requestRecord->department),
            ] : null,
            'decision' => $this->decision ? [
                'id' => $this->decision->id,
                'outcome' => $this->decision->outcome,
                'comment' => $this->decision->comment,
                'decided_at' => $this->decision->decided_at?->toIso8601String(),
                'decided_by' => $this->decision->decidedBy ? [
                    'id' => $this->decision->decidedBy->id,
                    'name' => $this->decision->decidedBy->name,
                ] : null,
            ] : null,
            'workflow_stage' => $stage ? [
                'order_no' => $stage->order_no,
                'code' => $stage->code,
                'name_ar' => $stage->name_ar,
                'name_en' => $stage->name_en,
            ] : null,
            'next_action' => $this->nextAction($closed, $inExecution, $executed, $stage),
            // Once final approval sends work for execution, the owning
            // department is the body carrying it out; before that, the stage's
            // responsible role is the authority holding the next checkpoint.
            'responsible_body' => $inExecution || $executed || $closed
                ? $this->namedEntity($requestRecord?->department, 'department')
                : $this->namedEntity($stage?->responsibleRole, 'role'),
            'execution_status' => $requestRecord?->status ? [
                'code' => $requestRecord->status->code,
                'name_ar' => $requestRecord->status->name_ar,
                'name_en' => $requestRecord->status->name_en,
                'color' => $requestRecord->status->color,
            ] : null,
            'can_mark_executed' => $inExecution && $atFinalStage,
            'can_close' => $executed && $atFinalStage,
        ];
    }

    /**
     * Stage 69 — three post-decision states, not two: Art. 38 keeps 18 (تحت
     * التنفيذ), 19 (منفذة) and 20 (مغلقة ومؤرشفة) apart, so the item's next
     * action is "record the effect", then "verify documentation and close",
     * then nothing. Extracted from an inline ternary chain that stopped being
     * readable once the third state arrived.
     *
     * @return array<string, mixed>|null
     */
    private function nextAction(bool $closed, bool $inExecution, bool $executed, mixed $stage): ?array
    {
        if ($closed) {
            return null;
        }

        if ($inExecution) {
            return [
                'code' => 'mark_executed',
                'name_ar' => 'تسجيل تنفيذ الأثر الإداري أو المالي',
                'name_en' => 'Record that the effect has been carried out',
            ];
        }

        if ($executed) {
            return [
                'code' => 'close_request',
                'name_ar' => 'التحقق من اكتمال التوثيق وإقفال المعاملة',
                'name_en' => 'Verify documentation and close the request',
            ];
        }

        return $stage ? [
            'code' => $stage->code,
            'name_ar' => $stage->name_ar,
            'name_en' => $stage->name_en,
        ] : null;
    }

    /** @return array<string, mixed>|null */
    private function namedEntity(mixed $entity, ?string $type = null): ?array
    {
        if ($entity === null) {
            return null;
        }

        return array_filter([
            'id' => $entity->id,
            'type' => $type,
            'code' => $entity->code ?? null,
            'name_ar' => $entity->name_ar,
            'name_en' => $entity->name_en,
        ], fn ($value) => $value !== null);
    }
}
