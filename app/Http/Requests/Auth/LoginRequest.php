<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the login payload before AuthController::login() runs.
 *
 * A FormRequest fails fast: if the rules below don't pass, Laravel returns 422
 * with the error messages and the controller is never entered — so the
 * controller can assume it has a well-formed email and a non-empty password.
 */
class LoginRequest extends FormRequest
{
    /**
     * Anyone may attempt to log in, so no authorisation check here.
     * (Brute-force protection is handled by the throttle middleware on the
     * route, not by this method.)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            // No min-length rule: the password only has to MATCH, and applying
            // strength rules at login would leak what the policy is.
            'password' => ['required', 'string'],
            // Optional label for the issued token, so tokens from different
            // clients can be told apart and revoked individually.
            'device_name' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
