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

class AuthController extends Controller
{
    /**
     * Issue a Sanctum personal access token for valid credentials.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        // Same message for unknown email and wrong password: don't leak which
        // accounts exist.
        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'بيانات الدخول غير صحيحة',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'هذا الحساب غير مفعّل. يرجى مراجعة مدير النظام.',
            ]);
        }

        $token = $user->createToken(
            $request->string('device_name')->value() ?: 'spa',
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load('roles', 'department')),
        ]);
    }

    /**
     * The authenticated user, with roles and department.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource(
            $request->user()->load('roles', 'department'),
        );
    }

    /**
     * Revoke only the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج بنجاح']);
    }
}
