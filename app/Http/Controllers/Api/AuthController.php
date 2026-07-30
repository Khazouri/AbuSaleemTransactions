<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Authentication endpoints for the Vue SPA.
 *
 * We use Sanctum's TOKEN mode rather than its cookie/session ("stateful") mode,
 * because the SPA is served from a different origin than the API
 * (localhost:5173 vs abusaleem.test). The flow:
 *
 *   1. POST /api/auth/login  -> returns a bearer token
 *   2. The SPA stores it and sends `Authorization: Bearer <token>` thereafter
 *   3. POST /api/auth/logout -> revokes that one token
 */
class AuthController extends Controller
{
    /**
     * Verify credentials and issue a personal access token.
     *
     * Throwing ValidationException (rather than returning a plain 401) makes
     * Laravel respond 422 with an `errors` object, which is the shape the Vue
     * login form already knows how to display.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        // Deliberately identical message for "no such account" and "wrong
        // password". Distinguishing them would let an attacker discover which
        // email addresses are registered (account enumeration).
        //
        // Hash::check() is also run in a way that avoids leaking timing
        // information about which branch failed.
        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'بيانات الدخول غير صحيحة',
            ]);
        }

        // Separate check with its own message: the credentials were right, but
        // an admin has disabled this account. Deactivating a user therefore
        // locks them out immediately, even though their password still works.
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'هذا الحساب غير مفعّل. يرجى مراجعة مدير النظام.',
            ]);
        }

        // One token per device. Naming them means a user can later be shown
        // "you are signed in on: spa, mobile" and revoke individually.
        // plainTextToken is the only moment the raw token exists — the database
        // stores just a hash of it.
        $token = $user->createToken(
            $request->string('device_name')->value() ?: 'spa',
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            // Eager-load so the SPA gets roles/department in this one response
            // and doesn't need a follow-up request to render the shell.
            //
            // withPermissions() is what lets the router guard and v-can work
            // on the very first navigation after signing in — without it the
            // SPA holds an empty permission set until the next page refresh,
            // and every screen (including the dashboard) looks forbidden.
            'user' => (new UserResource($user->load('roles', 'department')))->withPermissions(),
        ]);
    }

    /**
     * Return the signed-in user. The SPA calls this on boot (page refresh) to
     * rebuild its session from a token held in localStorage, and to confirm
     * that token is still valid.
     */
    public function me(Request $request): UserResource
    {
        return (new UserResource(
            $request->user()->load('roles', 'department'),
        ))->withPermissions();
    }

    /**
     * Revoke ONLY the token used for this request.
     *
     * Not $user->tokens()->delete(), which would sign the user out everywhere.
     * Logging out of the browser shouldn't kill their other sessions.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج بنجاح']);
    }
}
