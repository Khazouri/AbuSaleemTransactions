<?php

namespace App\Http\Requests\Register;

use App\Services\Reports\ReportExporter;
use Illuminate\Validation\Rule;

/**
 * Stage 80 — an export is the register request plus how to render it.
 *
 * Extends the read request so the file always describes exactly the population
 * the screen was showing; the two rule sets cannot drift apart. Same shape
 * Stage 24's ExportReportRequest established.
 */
class ExportRegisterRequest extends IndexRegisterRequest
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
