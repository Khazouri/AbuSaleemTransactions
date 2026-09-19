<?php

namespace App\Http\Requests\RequestDraft;

use App\Services\Lifecycle\DuplicatePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 88 — what an unsent intake is allowed to hold.
 *
 * EVERY field is optional, and that is the point rather than an oversight: a
 * draft is incomplete by definition, so requiring a title, a department or a
 * type would defeat the one thing it exists for. What is still validated is
 * shape — lengths and id types — so the payload column cannot become a dump
 * for arbitrary client data.
 *
 * Nothing here checks that a chosen department or type is still ACTIVE, and
 * nothing checks [D] Appendix 57 completeness or Appendix 16's duplicate rule.
 * All of those belong at submission, where StoreRequest already enforces them
 * against the payload the employee actually sends — a draft that has gone
 * stale should be refused when it is submitted, with the real message, not
 * silently refused a save while it is being typed.
 */
class SaveRequestDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            // Stage 90 — [G]'s «الأسباب», autosaved like every other field so
            // a half-typed reason survives a refresh too.
            'reasons' => ['nullable', 'string', 'max:5000'],
            // `integer` only — existence is checked at submission, so a draft
            // saved against a department that is retired in the meantime is
            // still resumable and still shows what was typed.
            'department_id' => ['nullable', 'integer'],
            'request_type_id' => ['nullable', 'integer'],
            'decision_grade' => ['nullable', 'integer', 'between:1,100'],
            'prior_relation' => ['nullable', Rule::in(array_keys(DuplicatePolicy::RELATIONS))],
        ];
    }

    public function messages(): array
    {
        return [
            'title.max' => 'لا يمكن أن يتجاوز عنوان الطلب 255 حرفاً.',
            'description.max' => 'لا يمكن أن يتجاوز وصف الطلب 5000 حرف.',
            'reasons.max' => 'لا يمكن أن تتجاوز أسباب الطلب 5000 حرف.',
            'decision_grade.integer' => 'يجب أن تكون درجة القرار رقماً صحيحاً.',
            'decision_grade.between' => 'يجب أن تكون درجة القرار بين 1 و100.',
            'prior_relation.in' => 'تصنيف العلاقة بالطلب السابق غير صالح.',
        ];
    }
}
