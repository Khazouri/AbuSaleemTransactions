<?php

namespace App\Http\Requests\RequestDraft;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stage 88 — a file uploaded against an unsent intake.
 *
 * The mime and size rules are StoreRequest's own, verbatim: a file this
 * endpoint accepts must still be acceptable when the draft is submitted, or a
 * draft could be built that can never be sent.
 *
 * Stage 90 narrowed both together to [G]'s «PDF أو صورة واضحة». BOTH had to
 * move, not either: StoreRequest's `attachments.*` rules never run for a
 * draft-backed submission, so narrowing only that one would have made a draft
 * the one remaining way to file a DOCX — the same shape of hole Stage 88 had
 * to close for the document key. A draft that already holds one is refused at
 * submission instead (StoreRequest::refusalForDraftFileTypes()).
 *
 * `required_document_key` is NOT validated against a type's matrix here, and
 * is optional. A draft may not have chosen its request type yet, and the keys
 * belong to one type's matrix — so the answer is stored as given and checked
 * at submission, where StoreRequest resolves the submitted type's own keys.
 * That is also what makes a type change on a half-built draft recoverable:
 * the keys stop matching, the form clears them, and the employee re-answers,
 * rather than the upload having been refused earlier for a type they had not
 * decided on.
 */
class StoreRequestDraftAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `max` is kilobytes, and this is the boundary — the intake
            // screen's own check is a convenience.
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
            'label' => ['nullable', 'string', 'max:255'],
            'required_document_key' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'يرجى اختيار ملف للرفع.',
            'file.file' => 'الملف المرفوع غير صالح.',
            'file.mimes' => 'يسمح بملفات PDF أو صورة واضحة (JPG أو PNG) فقط.',
            'file.max' => 'الحد الأقصى لحجم الملف هو 20 ميجابايت.',
            'label.max' => 'لا يمكن أن يتجاوز وصف المرفق 255 حرفاً.',
        ];
    }
}
