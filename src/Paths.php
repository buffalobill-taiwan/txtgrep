<?php
declare(strict_types=1);

namespace TxtGrep;

/** Translates between URL/DB logical paths (txt/<book>/<chapter>.txt) and disk paths. */
final class Paths
{
    public static function prefix(): string
    {
        return Config::get()->pathPrefix();
    }

    public static function logical(string $book, string $file): string
    {
        return self::prefix() . '/' . trim($book, '/') . '/' . basename($file);
    }

    /** Return the part after the configured prefix, or null when unsafe. */
    public static function relative(string $logical): ?string
    {
        $prefix = self::prefix() . '/';
        if (!str_starts_with($logical, $prefix)) {
            return null;
        }
        $relative = substr($logical, strlen($prefix));
        if ($relative === '' || !self::isSafe($relative)) {
            return null;
        }
        return $relative;
    }

    public static function book(string $logical): ?string
    {
        $relative = self::relative($logical);
        if ($relative === null || !str_contains($relative, '/')) {
            return null;
        }
        return explode('/', $relative, 2)[0];
    }

    /** Resolve a logical path to a real file inside the configured corpus. */
    public static function absolute(string $logical): ?string
    {
        $relative = self::relative($logical);
        if ($relative === null) {
            return null;
        }
        $corpus = realpath(Config::get()->corpus());
        $candidate = realpath(Config::get()->corpus() . '/' . $relative);
        return $corpus !== false && $candidate !== false
            && self::inside($candidate, $corpus) && is_file($candidate)
            ? $candidate : null;
    }

    public static function inside(string $candidate, string $corpus): bool
    {
        return str_starts_with($candidate, rtrim($corpus, '/') . '/');
    }

    /** Convert an absolute path inside the corpus back to a logical path. */
    public static function fromAbsolute(string $absolute): ?string
    {
        $corpus = realpath(Config::get()->corpus());
        $real = realpath($absolute);
        if ($corpus === false || $real === false
            || !self::inside($real, $corpus)) {
            return null;
        }
        return self::prefix() . '/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($corpus) + 1));
    }

    public static function label(string $logical, bool $withBook): string
    {
        $relative = self::relative($logical) ?? basename($logical);
        $label = $withBook ? $relative : basename($relative);
        return preg_replace('/\.txt$/iu', '', $label) ?? $label;
    }

    private static function isSafe(string $relative): bool
    {
        if (str_contains($relative, "\0") || str_contains($relative, '\\')) {
            return false;
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }
}
