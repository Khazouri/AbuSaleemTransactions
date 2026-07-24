<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a User for the API.
 *
 * Using a Resource rather than returning the model directly means we choose
 * exactly which fields go out. The model's $hidden already strips the password,
 * but this is the stronger guarantee: a column added to the users table later
 * cannot leak into API responses unless it is listed here.
 *
 * Laravel wraps resources in a `data` key, so the SPA reads `response.data.data`
 * — the auth store handles that.
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,

            // whenLoaded() omits the key entirely unless the relation was
            // eager-loaded. That prevents an accidental N+1 query here: the
            // resource never triggers a database call of its own.
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'code' => $this->department->code,
                'name_ar' => $this->department->name_ar,
                'name_en' => $this->department->name_en,
            ] : null),

            // The SPA uses these codes to decide what to show — role badges
            // now, and menu/route decisions later.
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'id' => $role->id,
                'code' => $role->code,
                'name_ar' => $role->name_ar,
                'name_en' => $role->name_en,
            ])->values()),

            // Stage 9 adds the resolved screen x action permission set here, so
            // the SPA can build its menu and hide buttons from one payload.
        ];
    }
}
