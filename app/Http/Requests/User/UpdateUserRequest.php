<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates edits to an existing user account.
 *
 * Password is the one field that differs in kind, not just in "sometimes":
 * it's nullable AND optional, because leaving it blank in the edit form means
 * "keep the current password" rather than "clear it" — see
 * UserController::update(), which only hashes and assigns it when present.
 */
class UpdateUserRequest extends FormRequest
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
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],

            'email' => [
                'sometimes', 'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id)->whereNull('deleted_at'),
            ],

            'password' => ['nullable', 'string', 'min:8'],

            'department_id' => ['nullable', 'integer', 'exists:departments,id'],

            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],

            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم المستخدم مطلوب.',
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email' => 'صيغة البريد الإلكتروني غير صحيحة.',
            'email.unique' => 'هذا البريد الإلكتروني مستخدم بالفعل.',
            'password.min' => 'كلمة المرور يجب ألا تقل عن 8 أحرف.',
            'department_id.exists' => 'الإدارة المحددة غير موجودة.',
            'role_ids.*.exists' => 'أحد الأدوار المحددة غير موجود.',
        ];
    }
}
