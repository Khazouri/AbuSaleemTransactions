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
        $closed = in_array($statusCode, ['completed_closed', 'archived'], true);

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
            'next_action' => $closed ? null : ($inExecution ? [
                'code' => 'complete_execution',
                'name_ar' => 'توثيق اكتمال التنفيذ وإغلاق الطلب',
                'name_en' => 'Confirm execution and close request',
            ] : ($stage ? [
                'code' => $stage->code,
                'name_ar' => $stage->name_ar,
                'name_en' => $stage->name_en,
            ] : null)),
            // Once final approval sends work for execution, the owning
            // department is the body carrying it out; before that, the stage's
            // responsible role is the authority holding the next checkpoint.
            'responsible_body' => $inExecution || $closed
                ? $this->namedEntity($requestRecord?->department, 'department')
                : $this->namedEntity($stage?->responsibleRole, 'role'),
            'execution_status' => $requestRecord?->status ? [
                'code' => $requestRecord->status->code,
                'name_ar' => $requestRecord->status->name_ar,
                'name_en' => $requestRecord->status->name_en,
                'color' => $requestRecord->status->color,
            ] : null,
            'can_complete' => $inExecution && $stage?->code === 'final_approval_archiving',
        ];
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
