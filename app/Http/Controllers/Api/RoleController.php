<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Roles: the list, plus creating and renaming them.
 *
 * Added in Stage 7 so the Users screen can offer roles as checkboxes when
 * assigning an account. Stage 8's matrix editor also reads this (via
 * ScreenRolePermissionController::index()) for its role tabs, and it is that
 * screen which creates and renames roles.
 *
 * There is no destroy(): users and audit rows reference roles, the same
 * "preserve, don't erase" reasoning as departments.
 */
class RoleController extends Controller
{
    /** Prefix of the codes this controller assigns; the built-in roles are R01…R12. */
    public const CUSTOM_PREFIX = 'C';

    public function index(): AnonymousResourceCollection
    {
        $roles = Role::query()->orderBy('code')->get();

        return RoleResource::collection($roles);
    }

    /**
     * A new role starts with no screen at all — the matrix shows it as all
     * off — and holds no workflow transition, so it can only ever grant
     * screen access.
     *
     * Its code gets its own prefix rather than continuing the R-numbers:
     * RoleSeeder upserts by code, so a later built-in R13 would otherwise
     * silently rename whatever custom role already held that code.
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = Role::create([...$request->validated(), 'code' => $this->nextCustomCode()]);

        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    /** Names and description only; the code never changes once assigned. */
    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $role->update($request->validated());

        return new RoleResource($role);
    }

    // ponytail: max+1 can race two simultaneous creates into the unique index (one 500s); fine for an R08-only screen.
    private function nextCustomCode(): string
    {
        $highest = Role::query()
            ->where('code', 'like', self::CUSTOM_PREFIX.'%')
            ->pluck('code')
            ->map(fn (string $code) => (int) substr($code, strlen(self::CUSTOM_PREFIX)))
            ->max() ?? 0;

        return self::CUSTOM_PREFIX.str_pad((string) ($highest + 1), 2, '0', STR_PAD_LEFT);
    }
}
