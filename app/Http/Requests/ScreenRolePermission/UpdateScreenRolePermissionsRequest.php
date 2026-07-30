<?php

namespace App\Http\Requests\ScreenRolePermission;

use App\Models\ScreenRolePermission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a bulk save of the permission matrix — Stage 8.
 *
 * The grid edits many (screen, role) cells at once, so the payload is a flat
 * list of rows rather than one field per cell; ScreenRolePermissionController
 * upserts each row keyed on [screen_id, role_id], matching the table's unique
 * constraint.
 */
class UpdateScreenRolePermissionsRequest extends FormRequest
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
        $rules = [
            'items' => ['required', 'array', 'min:1'],
            'items.*.screen_id' => ['required', 'integer', 'exists:screens,id'],
            'items.*.role_id' => ['required', 'integer', 'exists:roles,id'],
        ];

        // Same seven flags every row must send, sourced from the model so a
        // future eighth action only needs editing there.
        foreach (ScreenRolePermission::ACTIONS as $action) {
            $rules["items.*.{$action}"] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'يجب إرسال قائمة الصلاحيات.',
            'items.*.screen_id.required' => 'الشاشة مطلوبة لكل صف.',
            'items.*.screen_id.exists' => 'إحدى الشاشات المحددة غير موجودة.',
            'items.*.role_id.required' => 'الدور مطلوب لكل صف.',
            'items.*.role_id.exists' => 'أحد الأدوار المحددة غير موجود.',
        ];
    }
}
