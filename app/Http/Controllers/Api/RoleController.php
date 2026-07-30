<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only role list.
 *
 * Added in Stage 7 purely so the Users screen can offer the eight fixed
 * roles as checkboxes when assigning an account. Stage 8 (roles_permissions)
 * builds the actual matrix editor on top of this same table.
 */
class RoleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $roles = Role::query()->orderBy('code')->get();

        return RoleResource::collection($roles);
    }
}
