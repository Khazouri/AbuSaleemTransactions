<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ErpCallbackRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Erp\ErpIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Single sign-on from the EmployeePlus ERP (user decision 2026-10-23): an
 * OAuth 2.0 authorization-code flow with PKCE, the ERP as the authorization
 * server. The ERP only proves who someone is; what they may do here is still
 * their account's roles, so every route behind auth:sanctum works unchanged.
 *
 * The SPA makes and checks `state` (login CSRF, RFC 6749 §10.12) and holds the
 * PKCE verifier; this side holds the client secret and redeems the code
 * (ErpIdentity). Nothing here signs anyone in from an ERP token in a URL.
 */
class ErpAuthController extends Controller
{
    public function __construct(private readonly ErpIdentity $erp) {}

    /**
     * The public half of the client registration, for the SPA to build the
     * authorize redirect. 404 until configured, so the login screen shows the
     * button only where the flow can finish (DevTestUserController's gating).
     */
    public function config(): JsonResponse
    {
        self::abortUnlessConfigured();

        return response()->json([
            'authorize_url' => config('services.erp.authorize_url'),
            'client_id' => config('services.erp.client_id'),
            'redirect_uri' => config('services.erp.redirect_uri'),
        ]);
    }

    public function callback(ErpCallbackRequest $request): JsonResponse
    {
        self::abortUnlessConfigured();

        $erpUserId = $this->erp->userIdFromCode($request->validated('code'), $request->validated('code_verifier'));
        $user = User::query()->where('erp_user_id', $erpUserId)->first();

        // Naming the ERP id is safe: only someone who just signed in to the ERP
        // as that user can see it, and it is what R08 needs to link the account.
        if (! $user) {
            $this->refuse("حساب ERP رقم {$erpUserId} غير مربوط بحساب في هذا النظام. يرجى مراجعة مدير النظام.");
        }

        if (! $user->is_active) {
            $this->refuse('هذا الحساب غير مفعّل. يرجى مراجعة مدير النظام.');
        }

        // A taken-over ERP account must not become a system admin here; R08
        // keeps the local password. Checked at every sign-in, not at linking,
        // so a role granted later is covered too.
        if ($user->hasRole('R08')) {
            $this->refuse('حساب مدير النظام يدخل بكلمة المرور المحلية فقط.');
        }

        // Expiring, unlike a local token: leaving the ERP must end access here
        // within a working day, since nothing tells us when it happens.
        return AuthController::issueToken($user, 'erp', now()->addHours(config('services.erp.session_hours')));
    }

    /**
     * Self-linking: a signed-in user proves the ERP account is theirs by
     * signing in to it, which a number typed by R08 cannot prove.
     */
    public function link(ErpCallbackRequest $request): UserResource
    {
        self::abortUnlessConfigured();

        $erpUserId = $this->erp->userIdFromCode($request->validated('code'), $request->validated('code_verifier'));
        $user = $request->user();

        if (User::query()->where('erp_user_id', $erpUserId)->whereKeyNot($user->id)->exists()) {
            $this->refuse('حساب ERP هذا مربوط بمستخدم آخر. يرجى مراجعة مدير النظام.');
        }

        $user->update(['erp_user_id' => $erpUserId]);

        return (new UserResource($user->load('roles', 'department', 'manager:id,name')))->withPermissions();
    }

    /** Also gates ErpSummaryController: every ERP route 404s until configured. */
    public static function abortUnlessConfigured(): void
    {
        $erp = config('services.erp');
        $required = ['authorize_url', 'token_url', 'userinfo_url', 'client_id', 'client_secret', 'redirect_uri'];

        abort_if(collect($required)->contains(fn ($key) => blank($erp[$key] ?? null)), 404);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['code' => $message]);
    }
}
