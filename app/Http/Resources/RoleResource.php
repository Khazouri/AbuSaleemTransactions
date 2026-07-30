<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a Role for the API.
 *
 * Currently backs only the read-only role list the Users screen uses to
 * assign roles to an account (Stage 7). Stage 8 extends this alongside the
 * roles/permission matrix editor.
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
        ];
    }
}
