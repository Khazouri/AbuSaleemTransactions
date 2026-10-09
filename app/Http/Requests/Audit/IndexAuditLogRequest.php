<?php

namespace App\Http\Requests\Audit;

use App\Models\AuditLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates the audit viewer's read-only filters — Stage 22. */
class IndexAuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'action' => ['nullable', 'string', Rule::in(AuditLog::ACTIONS)],
            // A short registry key, never a class name — see AuditLog::modelKeys().
            'model' => ['nullable', 'string', Rule::in(array_keys(AuditLog::modelKeys()))],
            'record_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.exists' => 'المستخدم المحدد غير صالح.',
            'action.in' => 'نوع الإجراء المحدد غير صالح.',
            'model.in' => 'نوع السجل المحدد غير خاضع للتدقيق.',
            'date_to.after_or_equal' => 'يجب أن يكون تاريخ النهاية بعد تاريخ البداية أو مساوياً له.',
        ];
    }
}
