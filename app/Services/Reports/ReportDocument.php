<?php

namespace App\Services\Reports;

/**
 * Stage 24 — one exportable report, independent of the file format.
 *
 * Both writers take this and nothing else, which is what lets the same report
 * come out as a spreadsheet or a PDF without either writer knowing anything
 * about transactions, audit logs, or whatever is exported next.
 *
 * `rtl` travels with the document rather than being read from the app locale:
 * the export is generated for the language the user was looking at, which the
 * request states explicitly.
 */
class ReportDocument
{
    /**
     * @param  string  $slug  ASCII filename stem — the title is usually Arabic,
     *                        and a downloaded file's name has to survive being
     *                        emailed around and saved on any filesystem
     * @param  string  $title  Report heading, already in the target language
     * @param  array<int, string>  $columns  Column headings
     * @param  array<int, array<int, string|int|float|null>>  $rows  Body cells, column-aligned
     * @param  array<int, string>  $meta  Context lines (applied filters, generation time)
     * @param  array<int, array{label: string, value: string}>  $summary  KPI pairs printed above the table
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $meta = [],
        public readonly array $summary = [],
        public readonly bool $rtl = true,
    ) {}

    /** Timestamped filename, so repeated exports don't overwrite each other. */
    public function filename(string $extension): string
    {
        return $this->slug.'-'.now()->format('Ymd-His').'.'.$extension;
    }
}
