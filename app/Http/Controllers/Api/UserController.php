<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;

/**
 * CRUD for user accounts (المستخدمون) — Stage 7.
 *
 * Mirrors DepartmentController's shape: a flat, unpaginated list (staff
 * rosters here are small enough for the SPA to hold in memory) and no `show`
 * route, since index() already gives the SPA everything an edit form needs.
 */
class UserController extends Controller
{
    /**
     * Every user with department and roles eager-loaded, so the table and the
     * edit form render without a follow-up request per row.
     */
    public function index(): AnonymousResourceCollection
    {
        $users = User::query()
            ->with(['department', 'roles'])
            ->orderBy('name')
            ->get();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->safe()->except('role_ids');
        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);
        $user->roles()->sync($request->validated('role_ids', []));

        return (new UserResource($user->load('department', 'roles')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $data = $request->safe()->except(['role_ids', 'password']);

        // A blank/omitted password means "keep the current one" — only hash
        // and assign it when the admin actually typed a replacement.
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->validated('password'));
        }

        $user->update($data);

        if ($request->has('role_ids')) {
            $user->roles()->sync($request->validated('role_ids'));
        }

        return new UserResource($user->load('department', 'roles'));
    }

    /**
     * Flip is_active (تفعيل / تعطيل).
     *
     * Blocked against your own account: deactivating yourself with no other
     * System Admin signed in would lock everyone out of the system at once.
     */
    public function toggleActive(Request $request, User $user): UserResource|JsonResponse
    {
        if ($user->is($request->user())) {
            return response()->json([
                'message' => 'لا يمكنك تعطيل حسابك الخاص.',
            ], 422);
        }

        $user->update(['is_active' => ! $user->is_active]);

        return new UserResource($user->load('department', 'roles'));
    }

    /**
     * Soft-delete an account. Same self-protection as toggleActive — removing
     * your own account out from under your own session would be irreversible
     * from the UI you're currently using.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            return response()->json([
                'message' => 'لا يمكنك حذف حسابك الخاص.',
            ], 422);
        }

        $user->delete();

        return response()->json(null, 204);
    }
}
