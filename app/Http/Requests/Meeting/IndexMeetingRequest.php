<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'committee_id' => ['nullable', 'integer', Rule::exists('committees', 'id')],
            'status' => ['nullable', 'string', Rule::in(['scheduled', 'completed', 'cancelled'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    public function messages(): array
    {
        return [
            'committee_id.exists' => 'اللجنة المحددة غير موجودة.',
            'status.in' => 'حالة الاجتماع غير صالحة.',
            'date_to.after_or_equal' => 'يجب أن يكون تاريخ النهاية بعد تاريخ البداية أو مساوياً له.',
        ];
    }
}
