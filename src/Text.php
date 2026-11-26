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

    /** @param resource $handle
     *  @return \Generator<int, string>
     */
    public static function streamLines($handle, ?\HashContext $hash = null): \Generator
    {
        $pending = '';
        $afterCr = false;
        while (($chunk = fread($handle, 8192)) !== false && $chunk !== '') {
            if ($hash !== null) {
                hash_update($hash, $chunk);
            }
            if ($afterCr && $chunk[0] === "\n") {
                $chunk = substr($chunk, 1);
            }
            $afterCr = str_ends_with($chunk, "\r");
            $parts = preg_split('/\r\n|\r|\n/', $chunk);
            $parts[0] = $pending . $parts[0];
            $pending = array_pop($parts);
            foreach ($parts as $part) {
                yield $part;
            }
        }
        if ($pending !== '') {
            yield $pending;
        }
    }

    /** @return string[] */
    public static function lines(string $raw): array
    {
        $handle = fopen('php://memory', 'w+b');
        try {
            fwrite($handle, $raw);
            rewind($handle);
            return self::paragraphs($handle);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle
     *  @return string[]
     */
    public static function paragraphs($handle): array
    {
        $paragraphs = [];
        foreach (self::streamLines($handle) as $line) {
            if ($line !== '') {
                $paragraphs[] = self::scrub($line);
            }
        }
        return $paragraphs;
    }
}
