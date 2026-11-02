<?php
declare(strict_types=1);

namespace App\Core;

/** CSV download. A UTF-8 byte-order mark is written so Excel reads rupee signs and accents correctly. */
final class Csv
{
    /**
     * @param list<string> $header
     * @param list<list<scalar|null>> $rows
     */
    public static function send(string $filename, array $header, array $rows): never
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ',', '"', '\\');
        foreach ($rows as $row) {
            // a cell that starts like a formula would run when opened in a spreadsheet
            fputcsv($out, array_map(static fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+@\t\r") !== false ? "'" . $v : $v, $row), ',', '"', '\\');
        }
        fclose($out);
        exit;
    }
}
