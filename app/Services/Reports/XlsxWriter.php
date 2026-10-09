<?php

namespace App\Services\Reports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Stage 24 — renders a ReportDocument as a real .xlsx workbook. */
class XlsxWriter
{
    /** Matches --color-nav, so an exported sheet still looks like the app. */
    private const HEADER_FILL = 'FF0F5132';

    /** @return string Raw .xlsx bytes */
    public function write(ReportDocument $document): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // Excel mirrors the whole sheet — column A on the right, freeze panes,
        // scrollbars — from this one flag, which is why the writer needs to
        // know the document's direction at all.
        $sheet->setRightToLeft($document->rtl);
        $sheet->setTitle($this->safeSheetTitle($document->title));

        $row = $this->writeHeading($sheet, $document);
        $row = $this->writeSummary($sheet, $document, $row);
        $this->writeTable($sheet, $document, $row);

        return $this->render($spreadsheet);
    }

    /** @return int The next free row */
    private function writeHeading(Worksheet $sheet, ReportDocument $document): int
    {
        $lastColumn = $this->columnLetter(count($document->columns));

        $sheet->setCellValue('A1', $document->title);
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $row = 2;
        foreach ($document->meta as $line) {
            $sheet->setCellValue("A{$row}", $line);
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setSize(9);
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        return $row + 1;
    }

    /** KPI pairs sit above the table as label/value columns. */
    private function writeSummary(Worksheet $sheet, ReportDocument $document, int $row): int
    {
        if ($document->summary === []) {
            return $row;
        }

        foreach ($document->summary as $entry) {
            $sheet->setCellValue("A{$row}", $entry['label']);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            // Written as text so a value like "62.5%" or "—" survives intact
            // rather than being reinterpreted by Excel as a number or a date.
            $sheet->setCellValueExplicit("B{$row}", (string) $entry['value'], DataType::TYPE_STRING);
            $row++;
        }

        return $row + 1;
    }

    private function writeTable(Worksheet $sheet, ReportDocument $document, int $row): void
    {
        $lastColumn = $this->columnLetter(count($document->columns));
        $headerRow = $row;

        foreach ($document->columns as $index => $heading) {
            $sheet->setCellValue($this->columnLetter($index + 1).$row, $heading);
        }

        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        foreach ($document->rows as $line) {
            $row++;
            foreach (array_values($line) as $index => $value) {
                $cell = $this->columnLetter($index + 1).$row;

                // Reference numbers (2026-DEPT-000001) and similar codes are
                // strings that Excel would happily mangle into dates.
                if (is_string($value)) {
                    $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($cell, $value);
                }
            }
        }

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$row}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD1D5DB']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $sheet->freezePane("A{$headerRow}");
        $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$row}");

        foreach (range(1, count($document->columns)) as $index) {
            $sheet->getColumnDimension($this->columnLetter($index))->setAutoSize(true);
        }
    }

    private function render(Spreadsheet $spreadsheet): string
    {
        // PhpSpreadsheet only writes to a stream or a path; php://output is
        // captured here so callers get bytes and never a temp file to clean up.
        ob_start();
        (new Xlsx($spreadsheet))->save('php://output');
        $bytes = (string) ob_get_clean();

        // Charts and styles hold circular references; without this the objects
        // survive until the request ends, which matters when an export of a few
        // thousand rows is the largest thing in memory.
        $spreadsheet->disconnectWorksheets();

        return $bytes;
    }

    private function columnLetter(int $index): string
    {
        return Coordinate::stringFromColumnIndex(max($index, 1));
    }

    /** Excel rejects sheet names over 31 chars or containing []:*?/\ */
    private function safeSheetTitle(string $title): string
    {
        $clean = preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $title) ?? $title;

        return mb_substr(trim($clean), 0, 31) ?: 'Report';
    }
}
