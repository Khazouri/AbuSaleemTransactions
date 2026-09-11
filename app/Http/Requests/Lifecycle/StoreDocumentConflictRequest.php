<?php

namespace App\Http\Requests\Lifecycle;

use App\Models\RequestDocumentConflict;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Stage 83 — [D] Appendix 30 step 1: تحديد المستندات المتعارضة. */
class StoreDocumentConflictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'conflict_kind' => ['required', Rule::in(array_keys(RequestDocumentConflict::KINDS))],
            'detail' => ['required', 'string', 'max:2000'],
            'attachment_ids' => ['nullable', 'array', 'max:10'],
            'attachment_ids.*' => ['integer', 'exists:attachments,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'conflict_kind.required' => 'يجب تحديد نوع التعارض.',
            'conflict_kind.in' => 'نوع التعارض غير معروف.',
            'detail.required' => 'يجب تحديد المستندات المتعارضة ووجه الاختلاف بينها.',
            'attachment_ids.*.exists' => 'أحد المستندات المحددة غير موجود.',
        ];
    }
}
