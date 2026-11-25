<?php
declare(strict_types=1);

namespace TxtGrep;

/** Safe chapter lookup and natural-order navigation for reader.php. */
final class Reader
{
    /** Resolve either an explicit logical path or a DB inode to a safe logical path. */
    public static function resolve(string $file = '', string $inode = ''): ?string
    {
        if ($file !== '' && !str_contains($file, "\0") && Paths::absolute($file) !== null) {
            return $file;
        }
        if ($inode !== '' && ctype_digit($inode) && strlen($inode) <= 20) {
            $stmt = Database::get()->prepare(
                'SELECT path FROM files WHERE inode = :inode ORDER BY path COLLATE "' . Search::COLLATION . '" LIMIT 1'
            );
            $stmt->execute(['inode' => $inode]);
            $path = $stmt->fetchColumn();
            if (is_string($path) && Paths::absolute($path) !== null) {
                return $path;
            }
        }
        return null;
    }

    /** @return string[] Logical paths in natural chapter order. */
    public static function chapters(string $logical): array
    {
        $absolute = Paths::absolute($logical);
        if ($absolute === null) {
            return [];
        }
        $files = glob(dirname($absolute) . '/*.txt', GLOB_NOSORT) ?: [];
        $chapters = [];
        foreach ($files as $file) {
            $path = Paths::fromAbsolute($file);
            if ($path !== null) {
                $chapters[] = $path;
            }
        }
        usort($chapters, static fn (string $a, string $b): int => strnatcmp(basename($a), basename($b)));
        return $chapters;
    }

    /** @return string[] */
    public static function content(string $logical): array
    {
        $absolute = Paths::absolute($logical);
        if ($absolute === null) {
            return [];
        }
        $raw = file_get_contents($absolute);
        return $raw === false ? [] : Text::lines($raw);
    }

    public static function url(string $logical): string
    {
        return 'reader.php?file=' . rawurlencode($logical);
    }
}
