<?php

namespace App\Domain\Reporting;

/**
 * Small dependency-free PDF writer for the finance summary.
 *
 * This is intentionally limited to text reports: it keeps the report export reliable on the server without requiring
 * a browser or an external binary. The source JSON/CSV/XLSX remain available for detailed tabular analysis.
 */
class PdfSummaryWriter
{
    /**
     * @param  list<array{0:string,1:mixed}>  $rows
     */
    public static function make(string $title, array $rows): string
    {
        $lines = [$title, str_repeat('-', 92)];

        foreach ($rows as [$label, $value]) {
            $display = self::display($value);
            $lines[] = mb_substr($label, 0, 45).' : '.mb_substr($display, 0, 95);
        }

        $pages = array_chunk($lines, 46);
        $objects = [];
        $pageObjectIds = [];
        $nextId = 4;

        foreach ($pages as $pageLines) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $pageObjectIds[] = $pageId;

            $content = "BT\n/F1 10 Tf\n50 790 Td\n13 TL\n";

            foreach ($pageLines as $line) {
                $content .= '('.self::pdfText($line).") Tj\nT*\n";
            }

            $content .= "ET\n";

            $objects[$contentId] = '<< /Length '.strlen($content)." >>\nstream\n{$content}endstream";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
        }

        $kids = implode(' ', array_map(fn (int $id): string => "{$id} 0 R", $pageObjectIds));
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = "<< /Type /Pages /Kids [{$kids}] /Count ".count($pageObjectIds).' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%".chr(226).chr(227).chr(207).chr(211)."\n";
        $offsets = [0];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";

        for ($id = 1; $id <= $max; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        $pdf .= "trailer\n<< /Size ".($max + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    private static function display(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Oui' : 'Non';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string) $value;
    }

    private static function pdfText(string $value): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value) ?: $value;

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $encoded);
    }
}
