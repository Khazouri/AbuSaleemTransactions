<?php

namespace App\Http\Resources;

use App\Models\ScreenRolePermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes one (screen, role) cell of the permission matrix — Stage 8.
 */
class ScreenRolePermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'screen_id' => $this->screen_id,
            'role_id' => $this->role_id,
            ...array_combine(
                ScreenRolePermission::ACTIONS,
                array_map(fn (string $action) => (bool) $this->{$action}, ScreenRolePermission::ACTIONS),
            ),
        ];
    }
}
