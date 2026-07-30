<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a Role for the API.
 *
 * Backs the Users screen's role checkboxes (Stage 7) and the roles/permission
 * matrix editor's role tabs (Stage 8) — `description` was added for the
 * latter, as a tooltip/subtitle next to each role tab.
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'description' => $this->description,
        ];
    }
}
