<?php

/**
 * Renders USER_GUIDE.ar.md as an A4 PDF.
 *
 * mPDF rather than a headless browser or dompdf, for the reason
 * App\Services\Reports\PdfWriter already records: dompdf does no Arabic
 * shaping and prints Arabic as disconnected left-to-right letterforms. mPDF
 * ships Arabic-capable fonts and joins the script itself, which is why it is
 * already a dependency here — this script reuses that proven configuration
 * rather than introducing a second PDF path.
 *
 * Run from the repository root:
 *     php <this file> [output.pdf]
 */

declare(strict_types=1);

ini_set('memory_limit', '1024M');
set_time_limit(600);

$root = getcwd();
require $root.'/vendor/autoload.php';

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Mpdf\Mpdf;

$source = $root.'/USER_GUIDE.ar.md';
$target = $argv[1] ?? $root.'/USER_GUIDE.ar.pdf';

if (! is_file($source)) {
    fwrite(STDERR, "Source not found: {$source}\n");
    exit(1);
}

$markdown = file_get_contents($source);

/* ── 1. Normalise symbols the PDF fonts cannot draw ──────────────────────
   mPDF has no colour-emoji coverage, so an emoji left in place renders as a
   tofu box. Every one used in the guide is either decorative beside text that
   already says the same thing (the delay colours, the yes/no ticks) or a
   classifier this script reads before removing it (the callout markers). The
   one that carries meaning on its own — the signature mark beside an approval
   — becomes words instead. */
$markdown = str_replace([' 🖊️', ' 🖊'], ' (بتوقيع)', $markdown);

/* ── 2. Front matter belongs on the cover, not in the body ─────────────── */
$bodyStart = strpos($markdown, '## 1. ');
$body = $bodyStart === false ? $markdown : substr($markdown, $bodyStart);

/* ── 3. Markdown → HTML (GitHub flavour, for the tables) ────────────────── */
$environment = new Environment([
    'html_input' => 'allow',
    'allow_unsafe_links' => false,
]);
$environment->addExtension(new CommonMarkCoreExtension());
$environment->addExtension(new GithubFlavoredMarkdownExtension());
$html = (string) (new MarkdownConverter($environment))->convert($body);

/* ── 4. The twelve-stage map ────────────────────────────────────────────
   It is laid out with spaces, which only holds in a monospace font — and no
   monospace face carries Arabic, so left as a <pre> its columns collapse.
   Rebuilt as a real table, which mPDF lays out properly in RTL. */
$html = preg_replace_callback(
    '#<pre><code>(.*?)</code></pre>#s',
    static function (array $m): string {
        // Split on explicit line endings, NOT on \R: without the /u flag \R
        // also matches the single byte 0x85 (NEL), which occurs as a
        // continuation byte inside ordinary UTF-8 Arabic letters — so it cuts
        // words in half and every later match fails on malformed UTF-8.
        $lines = preg_split("/\r\n|\n|\r/", html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        $rows = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // The leading number is followed by one space on two-digit rows and
            // two on single-digit ones, so peel it off before splitting columns.
            if (! preg_match('/^(\d+)\s+(.+)$/u', $line, $parts)) {
                return $m[0];
            }
            $cols = preg_split('/\s{2,}/u', $parts[2]);
            if (count($cols) < 2) {
                return $m[0];
            }
            $rows[] = [
                'no' => $parts[1],
                'name' => $cols[0],
                'who' => $cols[1],
                'act' => implode(' ', array_slice($cols, 2)),
            ];
        }

        if ($rows === []) {
            return $m[0];
        }

        $out = '<table class="ladder"><thead><tr>'
            .'<th class="c-no">#</th><th>المرحلة</th><th>الفاعل</th><th>الإجراء</th>'
            .'</tr></thead><tbody>';

        foreach ($rows as $row) {
            $pivot = str_contains($row['act'], '←') ? ' class="pivot"' : '';
            $out .= '<tr'.$pivot.'>'
                .'<td class="c-no">'.e($row['no']).'</td>'
                .'<td class="c-name">'.e($row['name']).'</td>'
                .'<td class="c-who">'.e($row['who']).'</td>'
                .'<td>'.e($row['act']).'</td>'
                .'</tr>';
        }

        return $out.'</tbody></table>';
    },
    $html,
);

/* ── 5. Callout severity, taken from the marker the author already used ── */
$html = preg_replace_callback(
    '#<blockquote>(.*?)</blockquote>#s',
    static function (array $m): string {
        $class = 'note';
        if (str_contains($m[1], '🔒')) {
            $class = 'danger';
        } elseif (str_contains($m[1], '⚠')) {
            $class = 'warn';
        }

        return '<div class="callout '.$class.'">'.$m[1].'</div>';
    },
    $html,
);

/* ── 6. Chapter openers: a page break, a numeral, and a TOC entry ───────── */
$firstChapter = true;
$html = preg_replace_callback(
    '#<h2>(.*?)</h2>#s',
    static function (array $m) use (&$firstChapter): string {
        $text = trim(strip_tags($m[1]));
        $break = $firstChapter ? '' : '<pagebreak />';
        $firstChapter = false;

        $eyebrow = '';
        if (preg_match('/^(\d+)\.\s+(.+)$/u', $text, $parts)) {
            $eyebrow = '<div class="chapter-no">الفصل '.e($parts[1]).'</div>';
            $text = $parts[2];
        }

        return $break
            .'<tocentry content="'.e($text).'" level="0" />'
            .$eyebrow
            .'<h2>'.e($text).'</h2>';
    },
    $html,
);

/* Sub-sections join the contents one level in. */
$html = preg_replace_callback(
    '#<h3>(.*?)</h3>#s',
    static function (array $m): string {
        $text = trim(strip_tags($m[1]));

        return '<tocentry content="'.e($text).'" level="1" /><h3>'.e($text).'</h3>';
    },
    $html,
);

/* ── 7. Strip what is left of the decorative symbols ────────────────────
   Arrows (← →), the not-equal sign and the middle dot are kept: DejaVu draws
   them and they carry meaning in the tables. */
$html = preg_replace(
    '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u',
    '',
    $html,
);

/* Rules between sections are redundant once every chapter starts a page. */
$html = str_replace('<hr />', '', $html);

/* An escape hatch for checking the converted HTML without a PDF viewer. */
if (getenv('DUMP_HTML') !== false) {
    file_put_contents(getenv('DUMP_HTML'), $html);
}

/* ── 8. Render ─────────────────────────────────────────────────────────── */
$tempDir = $root.'/storage/app/mpdf';
if (! is_dir($tempDir)) {
    mkdir($tempDir, 0775, true);
}

$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'tempDir' => $tempDir,
    'margin_top' => 20,
    'margin_bottom' => 18,
    'margin_left' => 16,
    'margin_right' => 16,
    'margin_header' => 8,
    'margin_footer' => 9,
]);

// RIGHT-TO-LEFT. This MUST be the method call, not a 'directionality' key in
// the constructor config above: that key is not in mPDF 8's ConfigVariables at
// all, and the constructor hard-sets ltr, so passing it is silently ignored and
// the whole document renders left-to-right. SetDirectionality() is also what
// sets defaultAlign/defaultTableAlign to 'R' and BODY's direction, and it swaps
// the left/right margins, so it has to run before anything is written.
$mpdf->SetDirectionality('rtl');

// Per-run script detection, so an Arabic sentence carrying a Latin stage code
// renders both correctly inside one paragraph.
$mpdf->autoScriptToLang = true;
$mpdf->autoLangToFont = true;

// The guide sets arrows, ≠ and ≥ inside Arabic sentences. Script detection puts
// those runs in an Arabic face, which does not carry those glyphs — and mPDF's
// default is to draw nothing rather than borrow one, which shows up as a gap in
// exactly the tables where the arrow is the meaning. Substitution pulls the
// missing glyph from another loaded font instead.
$mpdf->useSubstitutions = true;
$mpdf->SetTitle('دليل استخدام نظام أبو سليم للطلبات');
$mpdf->SetAuthor('بلدية أبو سليم — لجنة شؤون الموظفين');

$mpdf->SetHTMLHeader(
    '<div dir="rtl" style="text-align:center;font-size:7.5pt;color:#8a9a93;border-bottom:0.3pt solid #dde5e1;padding-bottom:2mm;">'
    .'دليل استخدام النظام — لجنة شؤون الموظفين</div>',
);
$mpdf->SetHTMLFooter(
    '<div dir="rtl" style="text-align:center;font-size:8pt;color:#667f75;border-top:0.3pt solid #dde5e1;padding-top:2mm;">'
    .'{PAGENO} / {nbpg}</div>',
);

$css = <<<'CSS'
<style>
    body { font-size: 10.5pt; line-height: 1.75; color: #1d2b25; }
    p { margin: 0 0 5pt; text-align: justify; }
    strong { color: #10201a; }
    em { font-style: normal; font-weight: bold; }
    a { color: #0f5132; text-decoration: none; }

    h1.cover-title {
        font-size: 30pt; color: #0f5132; text-align: center;
        margin: 0 0 6pt; line-height: 1.25;
    }
    .cover-eyebrow { text-align: center; font-size: 11pt; color: #a8842a; margin-bottom: 22mm; }
    .cover-lede {
        text-align: center; font-size: 11pt; color: #3d5249;
        margin: 0 14mm 16mm; line-height: 1.9;
    }
    .cover-rule { border-top: 1.6pt solid #0f5132; border-bottom: 0.8pt solid #d4af37; margin: 0 30mm 12mm; }
    table.cover-facts { width: 100%; border-collapse: collapse; margin-top: 4mm; }
    table.cover-facts td {
        text-align: center; font-size: 9.5pt; color: #3d5249;
        border: 0.4pt solid #dde5e1; padding: 4mm 2mm;
    }
    table.cover-facts td b { display: block; font-size: 16pt; color: #0f5132; }
    .cover-foot { text-align: center; font-size: 9pt; color: #667f75; margin-top: 18mm; }

    h1.toc-title { font-size: 18pt; color: #0f5132; text-align: center; margin: 0 0 8mm; }

    .chapter-no { font-size: 9pt; color: #a8842a; margin-bottom: 1mm; }
    h2 {
        font-size: 18pt; color: #0f5132; margin: 0 0 6mm;
        padding-bottom: 3mm; border-bottom: 0.8pt solid #c3d1cb; line-height: 1.3;
    }
    h3 {
        font-size: 12.5pt; color: #10201a; margin: 8mm 0 3mm;
        padding-right: 3mm; border-right: 2.2pt solid #d4af37; line-height: 1.5;
    }

    ul, ol { margin: 0 0 5pt; padding-right: 5mm; }
    li { margin-bottom: 2pt; }

    code {
        font-family: dejavusansmono; font-size: 8.6pt;
        color: #0f5132; background-color: #eef2f1;
    }

    table { width: 100%; border-collapse: collapse; margin: 3mm 0 6mm; font-size: 9pt; }
    thead th {
        background-color: #e6efea; color: #0f5132; font-size: 8.6pt;
        text-align: right; padding: 2.6mm 2.2mm; border-bottom: 1pt solid #c3d1cb;
    }
    tbody td {
        padding: 2.4mm 2.2mm; border-bottom: 0.3pt solid #dde5e1;
        vertical-align: top; color: #3d5249;
    }

    table.ladder .c-no { width: 8%; text-align: center; color: #a8842a; font-weight: bold; }
    table.ladder .c-name { width: 34%; color: #10201a; font-weight: bold; }
    table.ladder .c-who { width: 22%; color: #0f5132; }
    table.ladder tr.pivot td { background-color: #faf3e0; }

    .callout {
        border-right: 2.4pt solid #0f5132; background-color: #f4f8f6;
        padding: 3mm 4mm; margin: 3mm 0 6mm; font-size: 9.6pt;
    }
    .callout.warn { border-right-color: #8a5a12; background-color: #fdf6e8; }
    .callout.danger { border-right-color: #9f2222; background-color: #fdf1f1; }
    .callout p { margin: 0 0 3pt; }
</style>
CSS;

$today = 'سبتمبر 2026';

$cover = <<<HTML
{$css}
<div dir="rtl" style="margin-top:38mm;">
    <div class="cover-eyebrow">بلدية أبو سليم — لجنة شؤون الموظفين</div>
    <h1 class="cover-title">دليل استخدام النظام<br />من البداية إلى النهاية</h1>
    <div class="cover-rule"></div>
    <div class="cover-lede">
        من لحظة تقديم الموظف لطلبه إلى إقفال الملف وأرشفته — مروراً بالمراجعة والمراجعة القانونية
        واجتماع اللجنة والتصويت والقرار والاعتماد والتنفيذ، وما قد يتفرع عن ذلك من نواقص
        واستثناءات وتظلمات. دليل واحد لكل الأدوار.
    </div>
    <table class="cover-facts">
        <tr>
            <td><b>13</b>فصلاً</td>
            <td><b>12</b>مرحلة</td>
            <td><b>11</b>دوراً</td>
            <td><b>33</b>شاشة</td>
            <td><b>4</b>بوابات رقابية</td>
        </tr>
    </table>
    <div class="cover-foot">الإصدار الأول — {$today}</div>
</div>
HTML;

$mpdf->WriteHTML($cover);

$mpdf->TOCpagebreakByArray([
    'toc-preHTML' => '<div dir="rtl"><h1 class="toc-title">المحتويات</h1></div>',
    'links' => true,
    'toc_id' => 0,
]);

// Belt and braces alongside SetDirectionality(): an explicit dir on the block
// itself, which is what App\Services\Reports\PdfWriter already relies on.
$mpdf->WriteHTML($css.'<div dir="rtl">'.$html.'</div>');

$mpdf->Output($target, 'F');

printf(
    "Wrote %s (%.1f KB, %d pages, direction=%s, align=%s)\n",
    $target,
    filesize($target) / 1024,
    $mpdf->page,
    $mpdf->directionality,
    $mpdf->defaultAlign,
);

/** HTML-escape helper, so this script does not need the Laravel helpers. */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
