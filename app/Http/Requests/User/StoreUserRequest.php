<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates creation of a user account.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Role/permission enforcement arrives in Stage 9; until then the
        // auth:sanctum middleware on the route is the only gate.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],

            'password' => ['required', 'string', 'min:8'],

            // Null is allowed — an account can exist before it's assigned to
            // a department.
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],

            // Direct-manager workflow redesign — resolves who reviews this
            // user's own transaction submissions.
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],

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
            'password.required' => 'كلمة المرور مطلوبة.',
            'password.min' => 'كلمة المرور يجب ألا تقل عن 8 أحرف.',
            'department_id.exists' => 'الإدارة المحددة غير موجودة.',
            'manager_id.exists' => 'المدير المباشر المحدد غير موجود.',
            'role_ids.*.exists' => 'أحد الأدوار المحددة غير موجود.',
        ];
    }
}
