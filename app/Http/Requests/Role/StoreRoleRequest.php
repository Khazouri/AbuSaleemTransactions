<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a new role's names.
 *
 * `code` is deliberately not a field: the workflow, the seeders and the
 * permission matrix all key off it, so RoleController assigns it and nothing
 * the client sends can choose or change it.
 */
class StoreRoleRequest extends FormRequest
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
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name_ar.required' => 'الاسم العربي للدور مطلوب.',
            'name_ar.max' => 'الاسم العربي للدور يجب ألا يتجاوز 255 حرفًا.',
            'name_en.max' => 'الاسم الإنجليزي للدور يجب ألا يتجاوز 255 حرفًا.',
            'description.max' => 'وصف الدور يجب ألا يتجاوز 255 حرفًا.',
        ];
    }
}
