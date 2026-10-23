<?php

namespace App\Http\Requests\User;

use App\Models\CommitteeMember;
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

            // Nullable: intake, not account creation, refuses an account
            // without one, since HR may issue the number later.
            'employee_number' => [
                'nullable', 'string', 'max:32',
                Rule::unique('users', 'employee_number'),
            ],

            // ERP single sign-on — see UpdateUserRequest.
            'erp_user_id' => [
                'nullable', 'integer', 'min:1',
                Rule::unique('users', 'erp_user_id'),
            ],

            'password' => ['required', 'string', 'min:8'],

            // Null is allowed — an account can exist before it's assigned to
            // a department.
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],

            // Direct-manager workflow redesign — resolves who reviews this
            // user's own request submissions.
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],

            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id', Rule::notIn(CommitteeMember::soleRoleIds())],

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
            'password.required' => 'كلمة المرور مطلوبة.',
            'password.min' => 'كلمة المرور يجب ألا تقل عن 8 أحرف.',
            'department_id.exists' => 'الإدارة المحددة غير موجودة.',
            'manager_id.exists' => 'المدير المباشر المحدد غير موجود.',
            'role_ids.*.exists' => 'أحد الأدوار المحددة غير موجود.',
            'role_ids.*.not_in' => 'أدوار اللجنة ودور العميد تُعيَّن من صفحة «المناصب الفردية».',
        ];
    }
}
