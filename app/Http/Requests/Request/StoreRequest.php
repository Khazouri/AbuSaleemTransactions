<?php

namespace App\Http\Requests\Request;

use App\Models\RequestType;
use App\Services\Lifecycle\DuplicatePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates an incoming request before it becomes a workflow request. */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // Intake must not route fresh work to retired master-data rows.
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where(
                fn ($query) => $query->where('is_active', true)->whereNotNull('code'),
            )],
            'request_type_id' => ['required', 'integer', Rule::exists('request_types', 'id')->where('is_active', true)],
            // Types with a threshold need a grade now; otherwise the ministry
            // branch could only guess after the request reached approval.
            'decision_grade' => [
                Rule::requiredIf(fn () => RequestType::query()
                    ->whereKey($this->integer('request_type_id'))
                    ->whereNotNull('decision_grade_threshold')
                    ->exists()),
                'nullable',
                'integer',
                'between:1,100',
            ],
            // Stage 83 — [D] Appendix 16. Format only: whether a classification
            // is *required* (and whether the chosen one is refused as belonging
            // to another mechanism) depends on this employee's own prior files,
            // which is a lookup, so DuplicatePolicy answers it in the
            // controller — the split StoreDecisionRequest's docblock already
            // established for rules that depend on state.
            'prior_relation' => ['nullable', Rule::in(array_keys(DuplicatePolicy::RELATIONS))],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*.file' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:20480'],
            'attachments.*.label' => ['nullable', 'string', 'max:255'],
            // Which of the chosen type's [D] Appendix 57 recommended documents
            // this file provides. Required, with `other` as an explicit answer
            // for material the matrix does not name — a submitter is asked, and
            // "nobody was asked" stays distinguishable from "the matrix has no
            // row for this".
            //
            // Validated against THIS type's own keys, so a key belonging to a
            // different type is refused: the client cannot produce one, but an
            // API caller can, and a mismatched key would otherwise be stored as
            // an answer to a question this request was never asked. Resolved
            // from the submitted request_type_id rather than a static list,
            // which is also why this cannot live in a plain Rule::in constant.
            //
            // The [D] Appendix 14 folder is NOT asked for here — it is derived
            // from the answer (RequestType::sectionForDocument()), so the
            // submitter answers one question about a file rather than two.
            'attachments.*.required_document_key' => ['required', Rule::in($this->allowedDocumentKeys())],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'عنوان الطلب مطلوب.',
            'department_id.required' => 'يرجى اختيار الإدارة.',
            'department_id.exists' => 'الإدارة المحددة غير صالحة أو غير مفعّلة.',
            'request_type_id.required' => 'يرجى اختيار نوع الطلب.',
            'request_type_id.exists' => 'نوع الطلب المحدد غير صالح أو غير مفعّل.',
            'decision_grade.required' => 'درجة القرار مطلوبة لهذا النوع من الطلبات.',
            'decision_grade.integer' => 'يجب أن تكون درجة القرار رقماً صحيحاً.',
            'decision_grade.between' => 'يجب أن تكون درجة القرار بين 1 و100.',
            'attachments.max' => 'لا يمكن إرفاق أكثر من 10 ملفات.',
            'attachments.*.file.required' => 'يرجى اختيار ملف للمرفق.',
            'attachments.*.file.mimes' => 'يسمح بملفات PDF وDOC وDOCX وJPG وPNG فقط.',
            'attachments.*.file.max' => 'الحد الأقصى لحجم الملف هو 20 ميجابايت.',
            'attachments.*.label.max' => 'لا يمكن أن يتجاوز وصف المرفق 255 حرفاً.',
            'attachments.*.required_document_key.required' => 'يجب تحديد نوع كل مستند مرفق من مستندات نوع الطلب.',
            'attachments.*.required_document_key.in' => 'نوع المستند المحدد لا يخص نوع الطلب المختار.',
        ];
    }

    /**
     * The document keys this request's own type offers, plus the `other`
     * escape.
     *
     * Reads RequestType::documentOptions(), the same method the intake options
     * endpoint renders the picker from and store() derives the folder with, so
     * the list offered and the list accepted are one list. An unknown or
     * inactive type yields just `other`, and the type rule above is what
     * reports that properly.
     *
     * @return list<string>
     */
    private function allowedDocumentKeys(): array
    {
        $type = RequestType::query()
            ->whereKey($this->integer('request_type_id'))
            ->first();

        return [
            ...array_keys($type?->documentOptions() ?? []),
            RequestType::OTHER_DOCUMENT,
        ];
    }
}
