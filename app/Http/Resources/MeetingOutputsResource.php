<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The meeting header, output funnel, and live request-level follow-up rows. */
class MeetingOutputsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $requestItems = $this->agendaItems->whereNotNull('transaction_id')->values();
        $decidedItems = $requestItems->filter(fn ($item) => $item->decision !== null);
        $advancedItems = $decidedItems->filter(
            fn ($item) => ($item->transaction?->currentStage?->order_no ?? 0) > 7,
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
                'in_execution' => $requestItems->where('transaction.status.code', 'in_execution')->count(),
                'completed_closed' => $requestItems
                    ->filter(fn ($item) => in_array(
                        $item->transaction?->status?->code,
                        ['completed_closed', 'archived'],
                        true,
                    ))
                    ->count(),
            ],
            'outputs' => MeetingOutputResource::collection($requestItems),
        ];
    }
}
