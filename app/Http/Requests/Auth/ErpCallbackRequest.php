<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The ERP's one-time authorization code and the PKCE verifier the SPA made
 * before redirecting. RFC 7636 §4.1 fixes the verifier at 43–128 characters
 * of [A-Z a-z 0-9 - . _ ~]; anything else is refused before the ERP is called.
 */
class ErpCallbackRequest extends FormRequest
{
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
            'code' => ['required', 'string', 'max:2048'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9\-._~]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'رمز الدخول من ERP مفقود. أعد المحاولة من البداية.',
            'code.max' => 'رمز الدخول من ERP غير صالح.',
            'code_verifier.*' => 'بيانات التحقق من الجلسة غير صالحة. أعد المحاولة من البداية.',
        ];
    }
}
