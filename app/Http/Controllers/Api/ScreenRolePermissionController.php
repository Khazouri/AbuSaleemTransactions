<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScreenRolePermission\UpdateScreenRolePermissionsRequest;
use App\Http\Resources\RoleResource;
use App\Http\Resources\ScreenResource;
use App\Http\Resources\ScreenRolePermissionResource;
use App\Models\Role;
use App\Models\Screen;
use App\Models\ScreenRolePermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The roles/permission matrix editor — Stage 8.
 *
 * One screen, all data: the grid needs every screen, every role and every
 * existing (screen, role) cell to render, so index() returns all three in a
 * single round trip rather than three separate endpoints the SPA would have
 * to join client-side.
 */
class ScreenRolePermissionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            // All screens, not just active ones — an admin preparing a screen's
            // permissions ahead of activating it shouldn't need is_active
            // flipped first.
            'screens' => ScreenResource::collection(Screen::query()->orderBy('sort_order')->get()),
            'roles' => RoleResource::collection(Role::query()->orderBy('code')->get()),
            'permissions' => ScreenRolePermissionResource::collection(ScreenRolePermission::all()),
        ]);
    }

    /**
     * Bulk-upsert the matrix.
     *
     * Blocked against removing your own role's view access to this very
     * screen: same self-lockout guard as UserController's toggleActive/destroy,
     * because nobody else could undo it for you once the grid disappears from
     * your own sidebar.
     */
    public function update(UpdateScreenRolePermissionsRequest $request): JsonResponse
    {
        $items = $request->validated('items');

        $rolesPermissionsScreenId = Screen::query()->where('code', 'roles_permissions')->value('id');
        $myRoleIds = $request->user()->roles()->pluck('roles.id')->all();

        foreach ($items as $item) {
            if ($item['screen_id'] === $rolesPermissionsScreenId
                && in_array($item['role_id'], $myRoleIds, true)
                && ! $item['can_view']) {
                return response()->json([
                    'message' => 'لا يمكنك إلغاء صلاحية عرض هذه الشاشة عن دورك الحالي؛ فهذا سيقفل وصولك إليها.',
                ], 422);
            }
        }

        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                ScreenRolePermission::updateOrCreate(
                    ['screen_id' => $item['screen_id'], 'role_id' => $item['role_id']],
                    Arr::only($item, ScreenRolePermission::ACTIONS),
                );
            }
        });

        return response()->json([
            'permissions' => ScreenRolePermissionResource::collection(ScreenRolePermission::all()),
        ]);
    }
}
