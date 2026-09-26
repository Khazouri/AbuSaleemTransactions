<?php

namespace App\Http\Requests\Department;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates edits to an existing department.
 *
 * Differs from the store request in two ways, both because a row already
 * exists: the unique check must ignore this department's own code, and the
 * parent must be checked for cycles.
 */
class UpdateDepartmentRequest extends FormRequest
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
        // Model-bound from the {department} route parameter.
        $department = $this->route('department');

        return [
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],

            'code' => [
                'nullable', 'string', 'max:50',
                // ignore() excludes this row, or saving a department without
                // changing its code would fail against itself.
                Rule::unique('departments', 'code')
                    ->ignore($department->id)
                    ->whereNull('deleted_at'),
            ],

            'parent_id' => [
                'nullable', 'integer', 'exists:departments,id',
                /**
                 * Cycle guard. Two illegal moves:
                 *
                 *   1. Making a department its own parent.
                 *   2. Moving it under one of its own descendants — e.g.
                 *      dragging "Administrative Affairs" beneath a team that
                 *      sits inside it. That branch would then reference itself
                 *      in a loop, disappearing from the tree and hanging any
                 *      recursive walk over it.
                 *
                 * Neither is caught by exists:, so it's checked explicitly.
                 */
                function (string $attribute, $value, $fail) use ($department) {
                    if ($value === null) {
                        return; // moving to the root is always fine
                    }

                    if ((int) $value === $department->id) {
                        $fail('لا يمكن جعل الإدارة تابعة لنفسها.');

                        return;
                    }

                    if (in_array((int) $value, $department->descendantIds(), true)) {
                        $fail('لا يمكن نقل الإدارة إلى إدارة فرعية تابعة لها.');
                    }
                },
            ],

            // The head must be an active member of THIS department, or the
            // hierarchy view would crown someone absent from its own list.
            // Only settable on edit: a new department has no members yet.
            'manager_user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')
                    ->where('department_id', $department->id)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
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
            'name_ar.required' => 'اسم الإدارة بالعربية مطلوب.',
            'code.unique' => 'هذا الرمز مستخدم لإدارة أخرى.',
            'parent_id.exists' => 'الإدارة الأم المحددة غير موجودة.',
            'manager_user_id.exists' => 'رئيس الإدارة يجب أن يكون موظفاً نشطاً في هذه الإدارة.',
        ];
    }
}
