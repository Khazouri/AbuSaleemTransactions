<?php

namespace App\Http\Requests\Audit;

use App\Services\Reports\ReportExporter;
use Illuminate\Validation\Rule;

/**
 * Stage 24 — the audit viewer's filters plus how to render them as a file.
 *
 * Extends the read request so the export always covers exactly the rows the
 * viewer was showing. Stage 22 seeded an `audit_log,export` grant but left it
 * unused, deferring exports to this stage; this is what finally uses it.
 */
class ExportAuditLogRequest extends IndexAuditLogRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'format' => ['nullable', 'string', Rule::in(ReportExporter::FORMATS)],
            'locale' => ['nullable', 'string', Rule::in(['ar', 'en'])],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'format.in' => 'صيغة التصدير المحددة غير مدعومة.',
            'locale.in' => 'اللغة المحددة غير مدعومة.',
        ]);
    }

    public function exportFormat(): string
    {
        return $this->validated('format') ?? 'xlsx';
    }

    public function exportLocale(): string
    {
        return $this->validated('locale') ?? 'ar';
    }
}
