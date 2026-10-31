<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The مقرر confirms جهة الاعتماد's decision on several files of one meeting
 * (user decision 2026-10-31): a list of agenda item ids. Whether each item
 * may be confirmed needs the item and the actor, so the controller checks it.
 */
class ConfirmAuthorityDecisionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['integer', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'يجب اختيار طلب واحد على الأقل.',
            'items.array' => 'يجب اختيار طلب واحد على الأقل.',
            'items.min' => 'يجب اختيار طلب واحد على الأقل.',
            'items.*.integer' => 'بند جدول الأعمال غير صحيح.',
            'items.*.distinct' => 'تكرر اختيار البند نفسه.',
        ];
    }
}
