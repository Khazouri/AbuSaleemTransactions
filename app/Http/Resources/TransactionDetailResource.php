<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** Full read model for one transaction's Stage 15 workspace. */
class TransactionDetailResource extends TransactionResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'created_by' => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null,
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'timeline' => $this->whenLoaded('stageLogs', function () {
                return $this->stageLogs->map(fn ($log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'comment' => $log->comment,
                    'from_stage' => $log->fromStage ? [
                        'order_no' => $log->fromStage->order_no,
                        'code' => $log->fromStage->code,
                        'name_ar' => $log->fromStage->name_ar,
                        'name_en' => $log->fromStage->name_en,
                    ] : null,
                    'to_stage' => $log->toStage ? [
                        'order_no' => $log->toStage->order_no,
                        'code' => $log->toStage->code,
                        'name_ar' => $log->toStage->name_ar,
                        'name_en' => $log->toStage->name_en,
                    ] : null,
                    'acted_by' => $log->actedBy ? [
                        'id' => $log->actedBy->id,
                        'name' => $log->actedBy->name,
                    ] : null,
                    'acted_at' => $log->acted_at?->toIso8601String(),
                ])->values();
            }),
            // This is calculated for the signed-in actor by the controller;
            // the service remains the final authority when it executes it.
            'available_actions' => $this->available_actions ?? [],
        ];
    }
}
