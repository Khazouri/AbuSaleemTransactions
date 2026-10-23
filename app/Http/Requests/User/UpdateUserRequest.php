<?php

namespace App\Http\Requests\User;

use App\Models\CommitteeMember;
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

            'employee_number' => [
                'nullable', 'string', 'max:32',
                Rule::unique('users', 'employee_number')->ignore($user->id),
            ],

            // ERP single sign-on — the ERP's user id. A self-link through the
            // ERP proves it; one typed here is trusted to R08 and audited.
            'erp_user_id' => [
                'nullable', 'integer', 'min:1',
                Rule::unique('users', 'erp_user_id')->ignore($user->id),
            ],

            'password' => ['nullable', 'string', 'min:8'],

            'department_id' => ['nullable', 'integer', 'exists:departments,id'],

            // A user cannot be their own manager — that would make the
            // direct-manager workflow stage permanently unreachable for them.
            'manager_id' => [
                'nullable', 'integer', 'exists:users,id',
                Rule::notIn([$user->id]),
            ],

            'role_ids' => ['sometimes', 'array'],
            // Stage 103 — a seat role the user already holds may ride along
            // (the edit form sends back every role), but none can be added.
            'role_ids.*' => [
                'integer', 'exists:roles,id',
                Rule::notIn(array_diff(CommitteeMember::soleRoleIds(), $user->roles()->pluck('roles.id')->all())),
            ],

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
            'employee_number.max' => 'لا يمكن أن يتجاوز الرقم الوظيفي 32 حرفاً.',
            'employee_number.unique' => 'هذا الرقم الوظيفي مسند لمستخدم آخر.',
            'erp_user_id.integer' => 'رقم المستخدم في ERP يجب أن يكون رقماً صحيحاً.',
            'erp_user_id.min' => 'رقم المستخدم في ERP يجب أن يكون رقماً صحيحاً.',
            'erp_user_id.unique' => 'حساب ERP هذا مربوط بمستخدم آخر.',
            'password.min' => 'كلمة المرور يجب ألا تقل عن 8 أحرف.',
            'department_id.exists' => 'الإدارة المحددة غير موجودة.',
            'manager_id.exists' => 'المدير المباشر المحدد غير موجود.',
            'manager_id.not_in' => 'لا يمكن أن يكون المستخدم مديره المباشر.',
            'role_ids.*.exists' => 'أحد الأدوار المحددة غير موجود.',
            'role_ids.*.not_in' => 'أدوار اللجنة ودور العميد تُعيَّن من صفحة «المناصب الفردية».',
        ];
    }
}
