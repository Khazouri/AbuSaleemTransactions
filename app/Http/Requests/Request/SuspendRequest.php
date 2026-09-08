<?php

namespace App\Http\Requests\Request;

use App\Models\RequestSuspension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 78 — [D] Art. 105's إيقاف إجرائي.
 *
 * `detail` is required, and that is the article's own requirement rather than
 * house style: Art. 105 fires only when a *specific* معلومة جوهرية or مستند
 * أساسي is in doubt, so a suspension that names neither would halt a file
 * without telling the legal review what to examine.
 */
class SuspendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ground' => ['required', Rule::in(array_keys(RequestSuspension::GROUNDS))],
            'detail' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'ground.required' => 'يرجى تحديد سبب الإيقاف الإجرائي.',
            'ground.in' => 'سبب الإيقاف الإجرائي غير معروف.',
            'detail.required' => 'يجب بيان المعلومة أو المستند محل الشك.',
            'detail.max' => 'بيان سبب الإيقاف أطول من الحد المسموح به.',
        ];
    }
}
