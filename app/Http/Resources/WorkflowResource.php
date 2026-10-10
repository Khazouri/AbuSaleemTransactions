<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Workflow designer — slice 2. A workflow and its versions, newest first, for the screen's picker. */
class WorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'versions' => $this->versions->map(fn ($version) => [
                'id' => $version->id,
                'number' => $version->number,
                'status' => $version->status,
                'cloned_from_version_id' => $version->cloned_from_version_id,
                'published_at' => $version->published_at?->toIso8601String(),
                'stages_count' => $version->stages_count,
            ]),
            'request_types' => $this->requestTypes->map->only(['id', 'code', 'name_ar', 'name_en'])->values(),
        ];
    }
}
