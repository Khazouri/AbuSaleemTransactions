<?php

namespace App\Http\Requests\Attachment;

use App\Models\Attachment;
use App\Models\Request;
use App\Models\RequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates uploads before a file is allowed into private application storage. */
class StoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `max` is kilobytes. Keeping this server-side is essential: a
            // browser's preflight check is only a convenience, not a boundary.
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:20480'],
            'label' => ['nullable', 'string', 'max:255'],
            // Stage 91 — the same question intake asks, asked here too, so a
            // document supplied during استكمال النواقص names the [D] Appendix
            // 57 row it would have named at intake. Deliberately NOT branched
            // by who is uploading: Art. 19's loop sends an incomplete file
            // back to requirements_check, where R02-R05 routinely attach on
            // the employee's behalf, so a submitter-only question would leave
            // staff unable to close the very gap this closes. The document
            // decides, not the uploader.
            //
            // Resolved from the route-bound request's own type, so a key
            // belonging to another type is refused — the same per-type rule
            // StoreRequest applies at intake, and the same reading of an
            // already-bound route model StoreMeetingAgendaRequest established.
            'required_document_key' => ['required', Rule::in($this->allowedDocumentKeys())],
            // Stage 80 — [D] Appendix 14's هيكل الملف الإلكتروني closes with
            // "ويمنع حفظ الملفات بصورة عشوائية دون تصنيف", so this is never
            // defaulted: a default would be a classification the uploader
            // never made.
            //
            // Stage 91 narrowed WHEN it is asked rather than weakening it. A
            // named matrix row already declares its folder, so asking again
            // would be one question too many and the answers could disagree —
            // the controller derives it instead. `other` means no row names
            // this document, which is exactly when the folder is a real
            // question, and it is what keeps R02-R05's genuine committee-cycle
            // uploads (مذكرة العرض، المحضر والقرار، الاعتماد، التنفيذ،
            // الإشعارات) filing where they belong. That is also why the list
            // stays the full twelve here rather than the submitter's three.
            'file_section' => [
                Rule::requiredIf(fn (): bool => $this->input('required_document_key') === RequestType::OTHER_DOCUMENT),
                'nullable',
                Rule::in(array_keys(Attachment::FILE_SECTIONS)),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'يرجى اختيار ملف للرفع.',
            'file.file' => 'الملف المرفوع غير صالح.',
            'file.mimes' => 'يسمح بملفات PDF وDOC وDOCX وJPG وPNG فقط.',
            'file.max' => 'الحد الأقصى لحجم الملف هو 20 ميجابايت.',
            'label.max' => 'لا يمكن أن يتجاوز وصف المرفق 255 حرفاً.',
            'required_document_key.required' => 'يجب تحديد أي مستند من مستندات نوع الطلب يقدمه هذا الملف.',
            'required_document_key.in' => 'نوع المستند المحدد لا يخص نوع هذا الطلب.',
            'file_section.required' => 'يجب تحديد قسم الملف الذي يحفظ فيه المستند.',
            'file_section.in' => 'قسم الملف غير صالح.',
        ];
    }

    /**
     * The document keys this request's own type offers, plus the `other`
     * escape.
     *
     * Reads RequestType::documentOptions(), the same method the upload form's
     * own lookup renders its picker from and store() derives the folder with,
     * so the list offered and the list accepted are one list. A request whose
     * type carries no matrix — or none at all — leaves just `other`, which
     * then asks for the folder rather than guessing one.
     *
     * @return list<string>
     */
    private function allowedDocumentKeys(): array
    {
        $requestRecord = $this->route('requestRecord');

        $type = $requestRecord instanceof Request ? $requestRecord->requestType : null;

        return [
            ...array_keys($type?->documentOptions() ?? []),
            RequestType::OTHER_DOCUMENT,
        ];
    }
}
