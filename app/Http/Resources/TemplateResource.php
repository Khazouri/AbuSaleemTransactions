<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The complete editable template payload; `category` filters which screen a template shows up on (e.g. Stage 35's decision recorder). */
class TemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'category' => $this->category,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'subject_ar' => $this->subject_ar,
            'subject_en' => $this->subject_en,
            'body_ar' => $this->body_ar,
            'body_en' => $this->body_en,
            'is_active' => $this->is_active,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
