<?php

namespace App\Http\Resources;

use App\Models\WorkflowStage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The meeting header, output funnel, and live request-level follow-up rows. */
class MeetingOutputsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Looked up by code, not hardcoded, so a stage renumber can't
        // silently change what "advanced past committee" means here.
        $committeeStageOrder = WorkflowStage::query()
            ->where('code', 'receive_from_committee')
            ->value('order_no') ?? 0;

        $requestItems = $this->agendaItems->whereNotNull('request_id')->values();
        $decidedItems = $requestItems->filter(fn ($item) => $item->decision !== null);
        $advancedItems = $decidedItems->filter(
            fn ($item) => ($item->request?->currentStage?->order_no ?? 0) > $committeeStageOrder,
        );

        return [
            'meeting' => [
                'id' => $this->id,
                'meeting_number' => $this->meeting_number,
                'title' => $this->title,
                'scheduled_at' => $this->scheduled_at?->toIso8601String(),
                'status' => $this->status,
                'committee' => $this->committee ? [
                    'id' => $this->committee->id,
                    'name_ar' => $this->committee->name_ar,
                    'name_en' => $this->committee->name_en,
                ] : null,
            ],
            'summary' => [
                'total_items' => $this->agendaItems->count(),
                'decisions' => $decidedItems->count(),
                'advanced' => $advancedItems->count(),
                'awaiting_action' => $decidedItems->count() - $advancedItems->count(),
                'in_execution' => $requestItems->where('request.status.code', 'in_execution')->count(),
                // Stage 69 — Art. 38 code 19, its own column now that منفذة and
                // مغلقة ومؤرشفة are two states rather than one.
                'executed' => $requestItems->where('request.status.code', 'executed')->count(),
                'completed_closed' => $requestItems
                    ->filter(fn ($item) => in_array(
                        $item->request?->status?->code,
                        ['completed_closed', 'archived'],
                        true,
                    ))
                    ->count(),
            ],
            'outputs' => MeetingOutputResource::collection($requestItems),
        ];
    }
}
