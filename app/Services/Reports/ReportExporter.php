<?php

namespace App\Services\Reports;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stage 24 — turns a ReportDocument into a downloadable HTTP response.
 *
 * The format-to-writer map lives here so every export endpoint offers exactly
 * the same set of formats; adding one is a single entry rather than a change
 * in each controller.
 */
class ReportExporter
{
    /** Supported `format` values, in the order the UI offers them. */
    public const FORMATS = ['xlsx', 'pdf'];

    private const MIME_TYPES = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pdf' => 'application/pdf',
    ];

    public function __construct(
        private readonly XlsxWriter $xlsx,
        private readonly PdfWriter $pdf,
    ) {}

    public function download(ReportDocument $document, string $format): Response
    {
        $bytes = match ($format) {
            'pdf' => $this->pdf->write($document),
            default => $this->xlsx->write($document),
        };
        $filename = $document->filename($format);

        return new Response($bytes, 200, [
            'Content-Type' => self::MIME_TYPES[$format] ?? self::MIME_TYPES['xlsx'],
            // makeDisposition emits both the plain and the RFC 5987 encoded
            // filename, so a name is still offered to clients that can't read
            // the UTF-8 form.
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
            ),
            'Content-Length' => (string) strlen($bytes),
            // Two exports of the same filtered report a minute apart must not
            // be served from an intermediate cache as one file.
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
