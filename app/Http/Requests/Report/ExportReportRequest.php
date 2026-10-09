<?php

namespace App\Http\Requests\Report;

use App\Services\Reports\ReportExporter;
use Illuminate\Validation\Rule;

/**
 * Stage 24 — an export is the report request plus how to render it.
 *
 * Extends the read request so the exported file always describes exactly the
 * population the screen was showing; the two rule sets cannot drift apart.
 */
class ExportReportRequest extends IndexReportRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'format' => ['nullable', 'string', Rule::in(ReportExporter::FORMATS)],
            // The export is generated in the language the user is reading, not
            // the server default — an Arabic screen must not produce an
            // English file.
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
