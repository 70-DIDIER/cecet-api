<?php

namespace App\Domain\Reporting;

/**
 * CSV for spreadsheets: semicolon separated, UTF-8 with BOM (opens directly in Excel), and values starting like a
 * formula neutralised (CSV injection): text typed by members can never be executed by a spreadsheet.
 */
final class CsvWriter
{
    /**
     * Writes the rows (associative arrays sharing the same keys) to an open stream and returns how many were written.
     *
     * @param  resource  $stream
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  list<string>  $header  used when there are no rows at all
     */
    public static function write($stream, iterable $rows, array $header = []): int
    {
        fwrite($stream, "\u{FEFF}");
        $count = 0;

        foreach ($rows as $row) {
            if ($count === 0) {
                fputcsv($stream, array_keys($row), ';', escape: '');
            }

            fputcsv($stream, array_map(self::cell(...), array_values($row)), ';', escape: '');
            $count++;
        }

        if ($count === 0 && $header !== []) {
            fputcsv($stream, $header, ';', escape: '');
        }

        return $count;
    }

    public static function cell(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($text) ? "'".$text : $text;
    }
}
