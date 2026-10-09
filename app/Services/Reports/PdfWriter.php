<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;

/**
 * Stage 24 — renders a ReportDocument as a PDF.
 *
 * mPDF rather than the more common dompdf: dompdf does no Arabic shaping, so
 * it prints Arabic as disconnected, left-to-right letterforms — unreadable for
 * this system's primary audience. mPDF ships Arabic-capable fonts and does the
 * joining itself, which is the whole reason it is a dependency here.
 */
class PdfWriter
{
    /** @return string Raw PDF bytes */
    public function write(ReportDocument $document): string
    {
        $direction = $document->rtl ? 'rtl' : 'ltr';

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L', // Report tables are wide; portrait truncates them.
            'directionality' => $direction,
            // mPDF writes font subsets and temporary page data to disk. Pointing
            // it inside storage/ keeps it out of the system temp directory,
            // which on Windows may not be writable by the web user.
            'tempDir' => $this->tempDir(),
            'margin_top' => 12,
            'margin_bottom' => 14,
            'margin_left' => 10,
            'margin_right' => 10,
        ]);

        // Turns on per-run script detection, so a report mixing Arabic labels
        // with Latin reference numbers renders both correctly in one cell.
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        $mpdf->SetTitle($document->title);
        $mpdf->SetHTMLFooter(
            '<div style="text-align:center;font-size:8pt;color:#6b7280;">{PAGENO} / {nbpg}</div>',
        );

        $mpdf->WriteHTML($this->html($document, $direction));

        // 'S' returns the document as a string instead of writing a file or
        // streaming to the browser — the controller owns the HTTP response.
        return $mpdf->Output('', 'S');
    }

    private function html(ReportDocument $document, string $direction): string
    {
        $meta = collect($document->meta)
            ->map(fn (string $line) => '<div class="meta">'.e($line).'</div>')
            ->implode('');

        $summary = $document->summary === [] ? '' : '<table class="summary"><tr>'.collect($document->summary)
            ->map(fn (array $entry) => '<td><span class="label">'.e($entry['label']).'</span>'
                .'<span class="value">'.e((string) $entry['value']).'</span></td>')
            ->implode('').'</tr></table>';

        $head = collect($document->columns)
            ->map(fn (string $heading) => '<th>'.e($heading).'</th>')
            ->implode('');

        $body = collect($document->rows)
            ->map(fn (array $row) => '<tr>'.collect($row)
                ->map(fn ($cell) => '<td>'.e((string) ($cell ?? '—')).'</td>')
                ->implode('').'</tr>')
            ->implode('');

        if ($body === '') {
            $empty = $document->rtl ? 'لا توجد بيانات مطابقة.' : 'No matching data.';
            $body = '<tr><td class="empty" colspan="'.count($document->columns).'">'.$empty.'</td></tr>';
        }

        return <<<HTML
        <style>
            body { font-size: 9pt; }
            h1 { font-size: 14pt; text-align: center; margin: 0 0 4pt; color: #0f5132; }
            .meta { text-align: center; font-size: 8pt; color: #6b7280; }
            .summary { width: 100%; margin: 10pt 0; border-collapse: collapse; }
            .summary td { border: 0.4pt solid #d1d5db; padding: 5pt; text-align: center; }
            .summary .label { display: block; font-size: 7.5pt; color: #6b7280; }
            .summary .value { display: block; font-size: 12pt; font-weight: bold; color: #0f5132; }
            table.data { width: 100%; border-collapse: collapse; margin-top: 6pt; }
            table.data th { background: #0f5132; color: #fff; font-size: 8pt; padding: 4pt; }
            table.data td { border-bottom: 0.4pt solid #e5e7eb; padding: 4pt; font-size: 8pt; }
            table.data td.empty { text-align: center; color: #6b7280; padding: 14pt; }
        </style>
        <div dir="{$direction}">
            <h1>{$document->title}</h1>
            {$meta}
            {$summary}
            <table class="data">
                <thead><tr>{$head}</tr></thead>
                <tbody>{$body}</tbody>
            </table>
        </div>
        HTML;
    }

    /** mPDF fails at construction rather than at render if this is missing. */
    private function tempDir(): string
    {
        $path = storage_path('app/mpdf');
        File::ensureDirectoryExists($path);

        return $path;
    }
}
