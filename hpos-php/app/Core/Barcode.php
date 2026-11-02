<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Code 39 barcodes as inline SVG, so carton labels print with no library or image files.
 * Handheld scanners read Code 39 out of the box. Supports A-Z, 0-9 and - . space.
 */
final class Barcode
{
    /** n = narrow, w = wide; nine elements per character, bar and space alternating from a bar. */
    private const PATTERNS = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn', '4' => 'nnnwwnnnw',
        '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw', '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn',
        'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw', 'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn',
        'F' => 'nnwnwwnnn', 'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww', 'O' => 'wnnnwnnwn',
        'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn', 'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn',
        'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw', 'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn',
        'Z' => 'nwwnwnnnn', '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '*' => 'nwnnwnwnn',
    ];

    public static function canEncode(string $text): bool
    {
        return $text !== '' && preg_match('/^[A-Z0-9\-. ]+$/', $text) === 1;
    }

    /** Bar widths as a list of [isBar, units] for "*TEXT*", with a narrow gap between characters. */
    public static function modules(string $text): array
    {
        if (!self::canEncode($text)) {
            throw new \InvalidArgumentException('Code 39 cannot encode this text');
        }
        $out = [];
        foreach (str_split('*' . $text . '*') as $i => $ch) {
            if ($i > 0) {
                $out[] = [false, 1];
            }
            foreach (str_split(self::PATTERNS[$ch]) as $n => $w) {
                $out[] = [$n % 2 === 0, $w === 'w' ? 3 : 1];
            }
        }
        return $out;
    }

    /** Inline SVG, $unit px per narrow bar, with the human-readable text underneath. */
    public static function svg(string $text, int $height = 56, int $unit = 2): string
    {
        $text = strtoupper($text);
        if (!self::canEncode($text)) {
            return '<span class="muted">' . e($text) . '</span>';
        }
        $quiet = 10 * $unit;
        $x = $quiet;
        $bars = '';
        foreach (self::modules($text) as [$isBar, $units]) {
            if ($isBar) {
                $bars .= '<rect x="' . $x . '" y="0" width="' . ($units * $unit) . '" height="' . $height . '"/>';
            }
            $x += $units * $unit;
        }
        $width = $x + $quiet;
        $total = $height + 16;
        return '<svg class="barcode" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $total . '" width="' . $width . '" height="' . $total . '" role="img" aria-label="Barcode ' . e($text) . '">'
            . '<rect width="' . $width . '" height="' . $total . '" fill="#fff"/><g fill="#000">' . $bars . '</g>'
            . '<text x="' . ($width / 2) . '" y="' . ($height + 13) . '" text-anchor="middle" font-family="monospace" font-size="12" fill="#000">' . e($text) . '</text></svg>';
    }
}
