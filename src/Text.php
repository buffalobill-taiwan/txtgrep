<?php
declare(strict_types=1);

namespace TxtGrep;

final class Text
{
    /** Replace invalid UTF-8 bytes with U+FFFD without changing line numbers. */
    public static function scrub(string $text): string
    {
        if (function_exists('mb_scrub')) {
            return mb_scrub($text, 'UTF-8');
        }
        $converted = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        return $converted === false ? preg_replace('/[^\x00-\x7F]/', '?', $text) ?? $text : $converted;
    }

    /** @return string[] */
    public static function lines(string $raw): array
    {
        $parts = preg_split('/\r\n|\r|\n/', $raw);
        if ($parts === false) {
            return [];
        }
        $lines = [];
        foreach ($parts as $part) {
            $line = self::scrub($part);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }
}
