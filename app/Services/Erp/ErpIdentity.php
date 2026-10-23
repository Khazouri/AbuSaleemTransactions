<?php

namespace App\Services\Erp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Turns an ERP authorization code into the ERP's user id — the only thing this
 * system ever takes from the ERP.
 *
 * The code is redeemed server to server with our client secret and the PKCE
 * verifier (RFC 6749 §4.1.3, RFC 7636), so a code stolen from the browser is
 * worthless on its own. `sub` comes from the userinfo endpoint over verified
 * TLS, which OIDC Core §3.1.3.7 accepts in place of checking a signature —
 * hence no JWT library.
 *
 * The ERP access token can read payroll and more in the ERP, so it lives only
 * in this method's local variable: never stored, logged or returned. Logs carry
 * the HTTP status alone, never a body, code, secret or token.
 */
class ErpIdentity
{
    public function userIdFromCode(string $code, string $verifier): int
    {
        $erp = config('services.erp');

        try {
            $token = Http::asForm()->timeout($erp['timeout'])->post($erp['token_url'], [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $erp['redirect_uri'],
                'client_id' => $erp['client_id'],
                'client_secret' => $erp['client_secret'],
                'code_verifier' => $verifier,
            ]);

            $accessToken = $token->successful() ? $token->json('access_token') : null;
            if (! is_string($accessToken) || $accessToken === '') {
                $this->fail('token', $token->status());
            }

            $userId = $this->userIdFromAccessToken($accessToken);
        } catch (ConnectionException) {
            Log::warning('ERP sign-in: the ERP could not be reached.');

            throw ValidationException::withMessages(['code' => 'تعذّر الاتصال بنظام ERP. حاول لاحقاً.']);
        }

        // userIdFromAccessToken() has already logged the status.
        return $userId ?? $this->refuse();
    }

    /**
     * Whose ERP access token this is, or null when the ERP refuses it. Also how
     * the ERP's summary call names its employee: the token is asked about and
     * dropped, under the same never-kept rule as above.
     *
     * @throws ConnectionException
     */
    public function userIdFromAccessToken(string $accessToken): ?int
    {
        $erp = config('services.erp');
        $userinfo = Http::withToken($accessToken)->timeout($erp['timeout'])->acceptJson()->get($erp['userinfo_url']);

        // A non-numeric or missing `sub` fails closed: it can't name an account.
        $sub = $userinfo->successful() ? $userinfo->json('sub') : null;
        $sub = is_scalar($sub) ? (string) $sub : '';
        if (! ctype_digit($sub) || (int) $sub < 1) {
            Log::warning('ERP userinfo refused the token.', ['status' => $userinfo->status()]);

            return null;
        }

        return (int) $sub;
    }

    private function fail(string $step, int $status): never
    {
        Log::warning("ERP sign-in: the {$step} step was refused.", ['status' => $status]);

        $this->refuse();
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['code' => 'تعذّر التحقق من حساب ERP. أعد المحاولة من البداية.']);
    }
}
