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
    /** @see withPermissions() */
    protected bool $includePermissions = false;

    /**
     * Opt in to the resolved screen x action permission map (Stage 9).
     *
     * Opt-IN rather than automatic, because resolving permissions costs a
     * query pair per user and only the signed-in user's own set is ever
     * useful — UserController::index() wraps the whole staff roster in this
     * same resource, where computing it for every row would be waste nobody
     * reads.
     *
     * Deliberately NOT inferred from $request->user(): during login the
     * request is unauthenticated (that's the point of the endpoint), so any
     * "is this the caller?" check silently omits the map from the one
     * response the SPA needs it in most.
     */
    public function withPermissions(): static
    {
        $this->includePermissions = true;

        return $this;
    }

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

            // Direct-manager workflow redesign — who reviews this user's own
            // request submissions. Same whenLoaded()/null shape as department.
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? [
                'id' => $this->manager->id,
                'name' => $this->manager->name,
            ] : null),

            // The SPA uses these codes to decide what to show — role badges
            // now, and menu/route decisions later.
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'id' => $role->id,
                'code' => $role->code,
                'name_ar' => $role->name_ar,
                'name_en' => $role->name_en,
            ])->values()),

            // Stage 9 — the resolved screen x action permission set, so the
            // SPA's router guard and v-can directive can check it without a
            // follow-up request. Present only when withPermissions() asked
            // for it (login and /auth/me).
            'permissions' => $this->when(
                $this->includePermissions,
                fn () => $this->screenPermissions(),
            ),
        ];
    }
}
