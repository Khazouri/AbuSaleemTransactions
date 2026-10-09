<?php

namespace App\Http\Requests\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stage 80 — [D] Art. 30's outward three: تاريخ الإحالة · رقم كتاب الإحالة ·
 * الجهة المحال إليها.
 *
 * All three are required because the article records them as a set and each is
 * knowable by the مقرر at the moment the file leaves — an إحالة with no letter
 * number or no named body is not a register entry, it is a note.
 *
 * Named without the doubled word, per AGENTS.md's convention for this one
 * resource (app/Http/Requests/Request/CloseRequest.php sets the precedent).
 */
class RecordApprovalReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'referred_at' => ['required', 'date'],
            'letter_number' => ['required', 'string', 'max:255'],
            'referred_to_body' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'referred_at.required' => 'يجب إدخال تاريخ الإحالة.',
            'referred_at.date' => 'صيغة تاريخ الإحالة غير صحيحة.',
            'letter_number.required' => 'يجب إدخال رقم كتاب الإحالة.',
            'letter_number.max' => 'لا يمكن أن يتجاوز رقم كتاب الإحالة 255 حرفاً.',
            'referred_to_body.required' => 'يجب تحديد الجهة المحال إليها.',
            'referred_to_body.max' => 'لا يمكن أن يتجاوز اسم الجهة المحال إليها 255 حرفاً.',
        ];
    }
}
