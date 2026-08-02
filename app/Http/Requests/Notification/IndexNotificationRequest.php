<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stage 23 — filters for the caller's own notification list.
 *
 * There is no user filter here on purpose: the controller scopes every query
 * to $request->user(), so whose notifications these are is never a request
 * parameter and can't be tampered with.
 */
class IndexNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unread' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'unread.boolean' => 'قيمة عامل التصفية «غير المقروءة» غير صحيحة.',
            'per_page.integer' => 'عدد العناصر في الصفحة يجب أن يكون رقماً.',
            'per_page.min' => 'عدد العناصر في الصفحة يجب ألا يقل عن 1.',
            'per_page.max' => 'عدد العناصر في الصفحة يجب ألا يزيد عن 100.',
        ];
    }
}
