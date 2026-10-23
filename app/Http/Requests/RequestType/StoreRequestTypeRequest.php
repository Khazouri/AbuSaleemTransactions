<?php

namespace App\Http\Requests\RequestType;

use App\Models\Attachment;
use App\Models\RequestType;
use App\Models\Workflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a new request type (نوع الطلب).
 *
 * Most fields are plain scalars other stages already read — default_sla_days
 * (Stage 17's due_date), decision_grade_threshold (Stage 18's ministry
 * escalation), the Appendix 21 legal basis (Stage 68). The one field with real structure is required_documents,
 * [D] Appendix 57's مصفوفة المستندات الإلزامية, validated row by row rather
 * than accepted as a JSON blob: it is read by two screens through
 * frontend/src/lib/requiredDocuments.js, so one malformed entry would break
 * the checklist for every reader of that matrix.
 */
class StoreRequestTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalises required_documents before the rules run.
     *
     * A condition whose two halves are both blank is stored as NULL, never as
     * {ar: '', en: ''} — documentCondition() tests whether the key is set, so
     * an empty pair would render a blank qualifier chip on the intake
     * checklist for every row someone merely opened in the editor.
     */
    protected function prepareForValidation(): void
    {
        // While the designer is off a type stays on the workflow it has (Standard
        // for a new one); a stale client cannot bind it to another.
        if (! config('app.workflow_designer')) {
            $this->getInputSource()->remove('workflow_id');
        }

        $documents = $this->input('required_documents');

        if (! is_array($documents)) {
            return;
        }

        $this->merge([
            'required_documents' => array_values(array_map(function ($document) {
                if (! is_array($document)) {
                    return $document;
                }

                $condition = $document['condition'] ?? null;

                if (is_array($condition)) {
                    $ar = trim((string) ($condition['ar'] ?? ''));
                    $en = trim((string) ($condition['en'] ?? ''));

                    $document['condition'] = ($ar === '' && $en === '')
                        ? null
                        : ['ar' => $ar ?: null, 'en' => $en ?: null];
                }

                return $document;
            }, $documents)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Nullable in the schema and display-only at runtime: nothing in
            // the application matches a type code against a literal (unlike
            // departments', which feeds the reference number). Still unique,
            // because the column is.
            'code' => ['nullable', 'string', 'max:50', Rule::unique('request_types', 'code')],

            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],

            // Stage 17 turns this into the request's due_date. Left nullable
            // deliberately: a type with no SLA simply never goes overdue,
            // which RequestDeadlineService already handles.
            'default_sla_days' => ['nullable', 'integer', 'min:1', 'max:365'],

            // Stage 18: at or above this grade the case must reach وزارة
            // الحكم المحلي rather than being settled inside the municipality.
            'decision_grade_threshold' => ['nullable', 'integer', 'min:1', 'max:100'],

            'is_active' => ['sometimes', 'boolean'],

            // Workflow designer — slice 3. Only a workflow with a published
            // version: intake files new requests onto that version.
            'workflow_id' => ['sometimes', 'integer', Rule::exists('workflow_versions', 'workflow_id')->where('status', 'published')],

            // Stage 56 — advisory only; all three routes stay selectable
            // whatever this says, so a wrong value misleads rather than blocks.
            'default_administrative_route' => [
                'nullable',
                Rule::in(RequestType::ADMINISTRATIVE_ROUTES),
            ],

            // Stage 68 — Appendix 21's citation, Arabic-only by design.
            'legal_basis_ar' => ['nullable', 'string', 'max:500'],
            'legal_basis_note_ar' => ['nullable', 'string', 'max:1000'],

            // Stage 72 — Appendix 57's matrix.
            'required_documents' => ['nullable', 'array'],
            // Every row is picked from the managed documents list (2026-10-23),
            // and a type lists a document once.
            'required_documents.*.document_id' => ['required', 'integer', 'distinct', Rule::exists('documents', 'id')],
            'required_documents.*.ar' => ['required', 'string', 'max:500'],
            'required_documents.*.en' => ['nullable', 'string', 'max:500'],
            'required_documents.*.group' => ['required', Rule::in(RequestType::DOCUMENT_GROUPS)],
            'required_documents.*.condition' => ['nullable', 'array'],
            'required_documents.*.condition.ar' => ['nullable', 'string', 'max:255'],
            'required_documents.*.condition.en' => ['nullable', 'string', 'max:255'],
            'required_documents.*.required' => ['sometimes', 'boolean'],
            'required_documents.*.section' => ['nullable', Rule::in(array_keys(Attachment::FILE_SECTIONS))],
        ];
    }

    /**
     * Workflow designer — slice 5. An attachment item names a document by its
     * key — the row's index plus a hash of its Arabic label — so renaming,
     * reordering or removing a row re-keys it, and the item then finds no
     * file on any new request of this type. Refused when the type's workflow
     * has a published version naming a key this save drops — or, when the
     * save moves the type onto a workflow, a key the type will not list.
     * A draft is W2's job at publish time.
     *
     * ponytail: retired versions are not read, so a file still running on
     * one can lose its document this way; reading them would mean a document
     * any old version named could never be renamed. Guard them too if that
     * ever bites.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = $this->route('requestType');
            $standardId = Workflow::standard()->id;
            $current = $type ? ($type->workflow_id ?? $standardId) : null;
            $target = (int) ($this->input('workflow_id') ?? $current ?? $standardId);
            $keys = fn (?array $documents) => array_keys((new RequestType)->forceFill(['required_documents' => $documents])->documentOptions());
            $kept = $keys($this->has('required_documents') ? $this->input('required_documents') : $type?->required_documents);
            // Moving onto a workflow, every key it names must be listed; staying, only the ones the type already had.
            $had = $current !== null && (int) $current === $target ? $keys($type->required_documents) : null;

            $lost = Workflow::query()->find($target)?->publishedVersion?->attachmentItems()
                ->first(fn (array $item) => ! in_array($item['document_key'], $kept, true)
                    && ($had === null || in_array($item['document_key'], $had, true)));

            if ($lost) {
                $validator->errors()->add('required_documents', sprintf(
                    $had === null
                        ? 'البند «%s» في مرحلة «%s» من مسار العمل يراجع مستندًا لا يرد في هذا النوع؛ أضفه إلى مستندات النوع أو اختر مسارًا آخر.'
                        : 'البند «%s» في مرحلة «%s» من مسار العمل يراجع مستندًا لن يبقى في هذا النوع بعد الحفظ؛ عدّل قائمة التحقق في مسودة جديدة وانشرها أولًا.',
                    $lost['label_ar'],
                    $lost['stage_name_ar'],
                ));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'رمز نوع الطلب مستخدم بالفعل.',
            'name_ar.required' => 'اسم نوع الطلب بالعربية مطلوب.',
            'default_sla_days.integer' => 'المدة المحددة يجب أن تكون عدداً من الأيام.',
            'default_sla_days.min' => 'المدة المحددة يجب ألا تقل عن يوم واحد.',
            'decision_grade_threshold.integer' => 'درجة القرار يجب أن تكون رقماً.',
            'default_administrative_route.in' => 'جهة الإحالة المقترحة غير معروفة.',
            'workflow_id.exists' => 'مسار العمل المحدد لا يملك إصدارًا منشورًا.',
            'required_documents.*.document_id.required' => 'اختر المستند من قائمة المستندات.',
            'required_documents.*.document_id.exists' => 'اختر المستند من قائمة المستندات.',
            'required_documents.*.document_id.distinct' => 'المستند مكرر في هذا النوع.',
            'required_documents.*.ar.required' => 'اسم المستند بالعربية مطلوب.',
            'required_documents.*.group.in' => 'تصنيف المستند غير معروف.',
            'required_documents.*.section.in' => 'مجلد المستند غير معروف.',
        ];
    }
}
