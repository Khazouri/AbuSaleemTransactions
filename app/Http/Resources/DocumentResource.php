<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of the managed documents list, as the Request Types screen lists
 * and picks it. Which types use the entry is not sent: the screen already holds
 * every type's rows and reads their `document_id`.
 */
class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'group' => $this->group,
            'condition_ar' => $this->condition_ar,
            'condition_en' => $this->condition_en,
            'required' => $this->required,
            'section' => $this->section,
            'is_active' => $this->is_active,
        ];
    }
}
