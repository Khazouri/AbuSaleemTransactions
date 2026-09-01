<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PresentationMemoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'derived' => $this->content['derived'] ?? null,
            'authored' => $this->content['authored'] ?? null,
            'generated_by' => $this->whenLoaded('generatedBy', fn () => $this->generatedBy ? [
                'id' => $this->generatedBy->id,
                'name' => $this->generatedBy->name,
            ] : null),
            'generated_at' => $this->generated_at?->toIso8601String(),
            'updated_by' => $this->whenLoaded('updatedBy', fn () => $this->updatedBy ? [
                'id' => $this->updatedBy->id,
                'name' => $this->updatedBy->name,
            ] : null),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
