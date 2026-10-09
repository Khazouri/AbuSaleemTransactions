<?php

namespace App\Http\Requests\Lifecycle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stage 83 — [D] Appendix 30 steps 2-4.
 *
 * All three are required together, and that is the whole enforcement of "**ولا
 * يجوز للجنة اختيار أحد المستندين بناءً على تقدير شخصي**": a resolution that
 * names no body addressed, no authoritative document and no correction is a
 * personal judgment, and it is simply not recordable.
 */
class ResolveDocumentConflictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'authority_consulted' => ['required', 'string', 'max:255'],
            'authoritative_document' => ['required', 'string', 'max:2000'],
            'correction_note' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'authority_consulted.required' => 'يجب بيان الجهة المختصة التي خوطبت بشأن التعارض.',
            'authoritative_document.required' => 'يجب تحديد المستند المعتمد.',
            'correction_note.required' => 'يجب إثبات تصحيح البيانات في ملف الموظف.',
        ];
    }
}
