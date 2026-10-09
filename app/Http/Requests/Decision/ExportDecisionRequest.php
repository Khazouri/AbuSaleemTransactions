<?php

namespace App\Http\Requests\Decision;

use App\Services\Reports\ReportExporter;
use Illuminate\Validation\Rule;

/**
 * Stage 25 — the register request plus how to render it, mirroring
 * Report\ExportReportRequest so both exports accept the same two extra keys.
 *
 * Note the accessor names: a FormRequest cannot declare `format()` — it would
 * clash with Illuminate\Http\Request::format($default) and fatal.
 */
class ExportDecisionRequest extends IndexDecisionRequest
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
