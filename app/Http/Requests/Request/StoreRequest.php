<?php

namespace App\Http\Requests\Request;

use App\Models\RequestDraftAttachment;
use App\Models\RequestType;
use App\Services\DocumentCompletenessService;
use App\Services\Lifecycle\DuplicatePolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates an incoming request before it becomes a workflow request. */
class StoreRequest extends FormRequest
{
    /**
     * Stage 90 — [G]'s «PDF أو صورة واضحة», as the mimes a draft's already
     * stored rows are checked against. The `mimes:` rule above states the same
     * set as extensions, which is what Laravel's rule speaks; this states it as
     * the content-guessed values `getMimeType()` recorded at upload. Keep the
     * two in step.
     */
    private const ACCEPTED_INTAKE_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    /**
     * Memo for draftAttachmentRows(); null means "not fetched yet", which is
     * distinguishable from a draft that genuinely holds no files.
     *
     * @var list<array{required_document_key: ?string, mime_type: ?string}>|null
     */
    private ?array $draftAttachmentRows = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Stage 88 — submission from a saved draft. The draft supplies the
            // FILES ONLY: every field still arrives in this payload and is
            // validated here, so what is filed is what the employee just read
            // back on the review screen, never a stale copy the draft happened
            // to be holding. One source of truth for the submission, and one
            // write path for it — the alternative, a second endpoint that
            // submits a draft, is the dual-path trap Stage 54's note records
            // and Stage 75 deleted a second closure path to avoid.
            //
            // Scoped to the caller's own drafts by the rule itself, so another
            // employee's draft reads as nonexistent rather than as forbidden.
            'draft_id' => [
                'nullable',
                'integer',
                Rule::exists('request_drafts', 'id')->where('created_by_user_id', $this->user()?->id),
            ],
            // Stage 95 — صاحب العلاقة. Optional, because an ordinary intake
            // is filed by the employee it is about and Request's own creating
            // hook defaults it to them. Whether a DIFFERENT person may be
            // named is a permission question, not a format one, so it is
            // answered in the controller — the same split this class already
            // documents for `prior_relation` below.
            'subject_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // Stage 90 — [G] lists «الأسباب» as an input of its own, and it
            // folded into `description` until now. Deliberately OPTIONAL:
            // `description` is nullable, so splitting a field out of it must
            // not silently raise the bar on the employee. The cap matches
            // SaveRequestDraftRequest's own, so a reason a draft accepted can
            // always be submitted.
            'reasons' => ['nullable', 'string', 'max:5000'],
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
            // Stage 85 raised this from 10. It has to clear the largest
            // Appendix 57 matrix or the submission rule below contradicts it:
            // TRNS alone states THIRTEEN documents unconditionally, so a cap of
            // ten would have made that type literally unfilable — refused for
            // missing documents it is simultaneously refused permission to
            // attach. The floor is the largest matrix (PEVG's 22 rows), plus
            // room for conditional rows and `other`; the number itself was
            // never sourced, it is Stage 13's own guard against a runaway
            // upload.
            //
            // Stage 88 — and refused outright alongside a `draft_id`: the two
            // are two answers to "which files is this request being filed
            // with", and silently merging them would file documents the review
            // screen never showed. A draft's files are edited on the draft.
            'attachments' => [
                Rule::prohibitedIf(fn (): bool => $this->filled('draft_id')),
                'nullable', 'array', 'max:30',
            ],
            // Stage 90 — narrowed from pdf,doc,docx,jpg,jpeg,png to reconcile
            // with [G]'s «PDF أو صورة واضحة». Scoped to the SUBMITTER's own
            // paths (here and StoreRequestDraftAttachmentRequest) and
            // deliberately not to AttachmentController or the appeal path,
            // which are shared with R02–R05 uploading genuine committee-cycle
            // documents — the same "who sees which question" split Stage 91
            // made about that endpoint. [D] names no file format anywhere, so
            // the constraint is [G]'s, and [G] specifies stage 1 only.
            //
            // ⚠ Consequence, stated rather than hidden: a document supplied
            // during استكمال النواقص may be a DOCX while the same document at
            // intake may not. That is the boundary of what [G] covers, not an
            // inconsistency this stage invents — a later stage holding [G]'s
            // sibling sheet for [F] step 5 should decide it, not inherit it.
            'attachments.*.file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
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

    /**
     * Stage 85 — [D] Appendix 57 stops advising and starts binding.
     *
     * Every row the appendix states WITHOUT a qualifier must be answered by
     * one of this submission's own files; a row carrying the appendix's own
     * inline condition ("بحسب الموضوع"، "عند الحاجة") stays optional. That is
     * the same distinction Stage 78's officer gate has read since it was
     * built — it refuses `not_applicable` on an unconditional row — so this
     * moves an existing bar to the moment the employee can act on it rather
     * than raising a new one. Until now the file only met that bar three hops
     * later, and the only way back was Art. 19's استكمال loop.
     *
     * It is also what finally lets [G]'s own «الرجاء إرفاق المستندات
     * المطلوبة» fire: no validation in this system could previously produce
     * that message, because `attachments` was nullable and nothing compared
     * what arrived against the matrix.
     *
     * An `after` hook rather than a rule on `attachments`, deliberately: the
     * failure this catches is most often a submission with NO attachments key
     * at all, and `nullable` short-circuits every rule attached to an absent
     * field — so the one case that matters most would be the one case the
     * rule never ran for.
     *
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // The type's own `exists` rule already reported an unknown or
            // retired type; naming documents from a type that failed would
            // report a second, confusing failure about the first one.
            if ($validator->errors()->has('request_type_id')) {
                return;
            }

            $documentKeys = $this->submittedDocumentKeys();

            // Stage 88 — a draft's files carry no `attachments.*` rules, so
            // the per-file question intake asks has to be asked of them here
            // or a draft-backed submission would be the one way to file an
            // unclassified document. A draft deliberately accepts a key
            // without checking it against a matrix (its type can still change
            // while it is being typed), which is exactly why the check has to
            // land at submission, against the type actually being filed.
            // Stage 90 — and the same is true of the file TYPE. Narrowing the
            // draft endpoint stops a new draft holding a DOCX, but a draft
            // created before this stage may already hold one, and it would
            // promote unchallenged for exactly the reason above. Reads the
            // stored mime (Symfony's content-guessed value, recorded at
            // upload) rather than the filename, since that is the fact the
            // server verified.
            if ($this->filled('draft_id')
                && ($typeRefusal = $this->refusalForDraftFileTypes()) !== null) {
                $validator->errors()->add('attachments', $typeRefusal);
            }

            if ($this->filled('draft_id')
                && ($keyRefusal = $this->refusalForDraftDocumentKeys($documentKeys)) !== null) {
                $validator->errors()->add('attachments', $keyRefusal);
            }

            $refusal = app(DocumentCompletenessService::class)->refusalForSubmission(
                RequestType::query()->whereKey($this->integer('request_type_id'))->first(),
                $documentKeys,
            );

            if ($refusal !== null) {
                // Reported against `attachments` even for a draft-backed
                // submission: it is the field the intake screen renders the
                // document list under, and the draft's files are that list.
                $validator->errors()->add('attachments', $refusal);
            }
        }];
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
            'draft_id.exists' => 'المسودة المحددة غير موجودة.',
            'attachments.prohibited' => 'لا يمكن إرفاق ملفات مع إرسال مسودة؛ تعدل مرفقات المسودة على المسودة نفسها.',
            'attachments.max' => 'لا يمكن إرفاق أكثر من 30 ملفاً.',
            'attachments.*.file.required' => 'يرجى اختيار ملف للمرفق.',
            'attachments.*.file.mimes' => 'يسمح بملفات PDF أو صورة واضحة (JPG أو PNG) فقط.',
            'attachments.*.file.max' => 'الحد الأقصى لحجم الملف هو 20 ميجابايت.',
            'attachments.*.label.max' => 'لا يمكن أن يتجاوز وصف المرفق 255 حرفاً.',
            'attachments.*.required_document_key.required' => 'يجب تحديد نوع كل مستند مرفق من مستندات نوع الطلب.',
            'attachments.*.required_document_key.in' => 'نوع المستند المحدد لا يخص نوع الطلب المختار.',
            'reasons.max' => 'لا يمكن أن تتجاوز أسباب الطلب 5000 حرف.',
        ];
    }

    /**
     * Stage 90 — why a draft's own files cannot be filed at all.
     *
     * [G] accepts «PDF أو صورة واضحة», and the intake rules above now say so —
     * but they never run for a draft-backed submission, so this is where the
     * same bar is applied to a draft's rows. Named in the same words the
     * inline path uses, because it is the same refusal about the same file.
     *
     * A row whose mime was never recorded is let through rather than refused:
     * the column is nullable, and refusing on an absent fact would reject a
     * file nobody has established anything about.
     */
    private function refusalForDraftFileTypes(): ?string
    {
        foreach ($this->draftAttachmentRows() as $row) {
            $mime = $row['mime_type'] ?? null;

            if ($mime !== null && ! in_array($mime, self::ACCEPTED_INTAKE_MIMES, true)) {
                return 'يسمح بملفات PDF أو صورة واضحة (JPG أو PNG) فقط.';
            }
        }

        return null;
    }

    /**
     * The draft's own attachment rows, fetched once.
     *
     * Two checks in after() read them — the document keys and the file types —
     * and a draft carries at most `attachments.max` rows, so one fetch shared
     * between them beats two queries over the same handful of records.
     *
     * @return list<array{required_document_key: ?string, mime_type: ?string}>
     */
    private function draftAttachmentRows(): array
    {
        return $this->draftAttachmentRows ??= RequestDraftAttachment::query()
            ->where('request_draft_id', $this->integer('draft_id'))
            ->get(['required_document_key', 'mime_type'])
            ->map(fn (RequestDraftAttachment $row): array => [
                'required_document_key' => $row->required_document_key,
                'mime_type' => $row->mime_type,
            ])
            ->all();
    }

    /**
     * Why a draft's own files are not classified for the type being filed.
     *
     * The same two failures `attachments.*.required_document_key` reports for
     * an inline upload, in the same words — a draft-backed submission should
     * be refused for the same reason, phrased the same way.
     *
     * @param  list<string|null>  $documentKeys
     */
    private function refusalForDraftDocumentKeys(array $documentKeys): ?string
    {
        $allowed = $this->allowedDocumentKeys();

        foreach ($documentKeys as $key) {
            if ($key === null || $key === '') {
                return 'يجب تحديد نوع كل مستند مرفق من مستندات نوع الطلب.';
            }

            if (! in_array($key, $allowed, true)) {
                return 'نوع المستند المحدد لا يخص نوع الطلب المختار.';
            }
        }

        return null;
    }

    /**
     * Stage 88 — which [D] Appendix 57 rows this submission's files answer,
     * whichever of the two sources the files came from.
     *
     * A draft-backed submission carries no `attachments` at all, so reading
     * the payload alone would report every mandatory row as uncovered and
     * refuse a complete file. Reading the draft's own rows instead is what
     * keeps ONE completeness rule — DocumentCompletenessService already takes
     * an iterable of keys, so it needs no change — rather than a second,
     * softer bar for drafts.
     *
     * @return list<string|null>
     */
    private function submittedDocumentKeys(): array
    {
        if ($this->filled('draft_id')) {
            return array_column($this->draftAttachmentRows(), 'required_document_key');
        }

        $attachments = $this->input('attachments');

        return array_column(is_array($attachments) ? $attachments : [], 'required_document_key');
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
