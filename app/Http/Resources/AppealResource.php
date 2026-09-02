<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One appeal, as the `appeals` screen sees it — Stage 58. */
class AppealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appellant' => $this->whenLoaded('appellant', fn () => $this->appellant ? [
                'id' => $this->appellant->id,
                'name' => $this->appellant->name,
            ] : null),
            'original_request' => $this->whenLoaded('originalRequest', fn () => $this->originalRequest ? [
                'id' => $this->originalRequest->id,
                'reference_number' => $this->originalRequest->reference_number,
                'title' => $this->originalRequest->title,
            ] : null),
            'original_decision_id' => $this->original_decision_id,
            'original_decision_reference' => $this->original_decision_reference,
            'original_decision_date' => $this->original_decision_date?->toDateString(),
            'status' => $this->whenLoaded('status', fn () => $this->status ? [
                'code' => $this->status->code,
                'name_ar' => $this->status->name_ar,
                'name_en' => $this->status->name_en,
                'color' => $this->status->color,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
