<?php

namespace App\Http\Requests\Department;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates creation of a department.
 *
 * No cycle check is needed here: a brand-new department has no descendants, so
 * any existing department is a legal parent. That check only matters when
 * MOVING one — see UpdateDepartmentRequest.
 */
class StoreDepartmentRequest extends FormRequest
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
            // Arabic name is the primary label and always required.
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],

            'code' => [
                'nullable', 'string', 'max:50',
                // Codes appear in transaction reference numbers, so they must
                // be unique among LIVE departments. Ignoring soft-deleted rows
                // lets a retired department's code be reused.
                Rule::unique('departments', 'code')->whereNull('deleted_at'),
            ],

            // Null means this sits at the root of the tree.
            'parent_id' => ['nullable', 'integer', 'exists:departments,id'],

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
        ];
    }
}
