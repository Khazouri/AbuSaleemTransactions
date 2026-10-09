<?php

namespace App\Http\Requests\Request;

use App\Services\DocumentReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 98 — [D] Appendix 6 row 3's تجهيز الملف الوظيفي, one verdict per
 * attached file, keyed by attachment id.
 *
 * Named without the doubled word, per AGENTS.md's convention for this one
 * resource. Format only: which files are under review, and whether the
 * reviewer opened each one, are resolved by DocumentReview.
 */
class PrepareEmploymentFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'documents' => ['present', 'array'],
            'documents.*' => [Rule::in(DocumentReview::ANSWERS)],
            'assembled' => ['required', 'boolean'],
            'grade_verified' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'documents.present' => 'يجب إرسال إجابات مستندات الملف الوظيفي.',
            'documents.array' => 'صيغة إجابات مستندات الملف الوظيفي غير صالحة.',
            'documents.*.in' => 'إجابة مستند الملف الوظيفي غير صالحة.',
            'assembled.required' => 'يرجى الإجابة عن سؤال تجهيز الملف الوظيفي.',
            'assembled.boolean' => 'إجابة سؤال تجهيز الملف الوظيفي غير صالحة.',
            'grade_verified.required' => 'يرجى الإجابة عن سؤال مطابقة البيانات الوظيفية.',
            'grade_verified.boolean' => 'إجابة سؤال مطابقة البيانات الوظيفية غير صالحة.',
        ];
    }
}
