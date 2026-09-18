<?php

namespace App\Http\Requests\Request;

use App\Models\Attachment;
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
            // [D] Appendix 14 closes with "ويمنع حفظ الملفات بصورة عشوائية دون
            // تصنيف", and intake was the one write path still producing an
            // unclassified row: StoreAttachmentRequest has required this since
            // Stage 80, but a submitter's own files come in through here. The
            // list is narrowed to Attachment::SUBMITTER_FILE_SECTIONS — see
            // that constant for why offering the committee-cycle folders to an
            // employee would be worse than not asking.
            'attachments.*.file_section' => ['required', Rule::in(Attachment::SUBMITTER_FILE_SECTIONS)],
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
            'attachments.*.file_section.required' => 'يجب تحديد نوع كل مستند مرفق.',
            'attachments.*.file_section.in' => 'نوع المستند غير صالح لمرفقات مقدم الطلب.',
        ];
    }
}
