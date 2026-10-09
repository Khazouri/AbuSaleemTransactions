<?php

namespace App\Http\Requests\Notification;

use App\Models\NotificationSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 23 — the caller's own channel preferences.
 *
 * The event-type keys are validated against NotificationSetting::EVENT_TYPES
 * rather than against the table, so a request can only ever write a row for an
 * event some notification class can actually raise. Same reasoning as the
 * audit log's model filter: the client speaks a registry key, never free text.
 */
class UpdateNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $eventTypes = array_keys(NotificationSetting::EVENT_TYPES);

        return [
            'settings' => ['required', 'array'],
            'settings.*.event_type' => ['required', 'string', Rule::in($eventTypes)],
            'settings.*.in_app' => ['required', 'boolean'],
            'settings.*.email' => ['required', 'boolean'],
            'settings.*.sms' => ['required', 'boolean'],

            // The user's own SMS contact detail travels with the preferences
            // that decide whether it is used at all. Explicit null clears it.
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'settings.required' => 'يجب إرسال إعدادات الإشعارات.',
            'settings.array' => 'صيغة إعدادات الإشعارات غير صحيحة.',
            'settings.*.event_type.required' => 'نوع الحدث مطلوب.',
            'settings.*.event_type.in' => 'نوع الحدث المحدد غير معروف.',
            'settings.*.in_app.required' => 'يجب تحديد حالة الإشعار داخل النظام.',
            'settings.*.in_app.boolean' => 'قيمة الإشعار داخل النظام غير صحيحة.',
            'settings.*.email.required' => 'يجب تحديد حالة الإشعار بالبريد الإلكتروني.',
            'settings.*.email.boolean' => 'قيمة الإشعار بالبريد الإلكتروني غير صحيحة.',
            'settings.*.sms.required' => 'يجب تحديد حالة الإشعار بالرسائل النصية.',
            'settings.*.sms.boolean' => 'قيمة الإشعار بالرسائل النصية غير صحيحة.',
            'phone.max' => 'رقم الهاتف يجب ألا يزيد عن 32 خانة.',
        ];
    }
}
