<?php

namespace App\Http\Requests\Performance;

use App\Services\Reports\ReportExporter;
use Illuminate\Validation\Rule;

/**
 * Stage 81 — a periodic report as a file.
 *
 * Extends the read request so the document always describes exactly the period
 * the screen was showing; the two rule sets cannot drift apart. Same shape
 * Stage 24's ExportReportRequest established and Stage 80 reused.
 */
class ExportPeriodicReportRequest extends IndexPerformanceRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'format' => ['nullable', 'string', Rule::in(ReportExporter::FORMATS)],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'format.in' => 'صيغة التصدير المحددة غير مدعومة.',
        ]);
    }

    public function exportFormat(): string
    {
        return $this->validated('format') ?? 'xlsx';
    }
}
