<?php
declare(strict_types=1);

namespace TxtGrep;

/** Safe chapter lookup and natural-order navigation for reader.php. */
final class Reader
{
    /**
     * Resolve a DB file id, a legacy inode, or an explicit logical path
     * to a safe logical path.
     */
    public static function resolve(string $file = '', string $inode = '', string $id = ''): ?string
    {
        if ($id !== '' && ctype_digit($id)) {
            $stmt = Database::get()->prepare('SELECT path FROM files WHERE id = :id');
            $stmt->execute(['id' => (int) $id]);
            $path = $stmt->fetchColumn();
            if (is_string($path) && Paths::absolute($path) !== null) {
                return $path;
            }
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
        if ($file !== '' && !str_contains($file, "\0") && Paths::absolute($file) !== null) {
            return $file;
        }
        return null;
    }

    /** Return the stable DB id for a logical path, if it is indexed. */
    public static function id(string $logical): ?int
    {
        if (Paths::absolute($logical) === null) {
            return null;
        }
        $stmt = Database::get()->prepare('SELECT id FROM files WHERE path = :path');
        $stmt->execute(['path' => $logical]);
        $value = $stmt->fetchColumn();
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<int, array{path: string, id: ?int}> Indexed chapters in natural order.
     */
    public static function chapterRows(string $logical): array
    {
        $relative = Paths::relative($logical);
        if ($relative === null || Paths::absolute($logical) === null) {
            return [];
        }
        $directory = dirname($relative);
        $prefix = Paths::prefix() . '/' . ($directory === '.' ? '' : $directory . '/');
        $stmt = Database::get()->prepare(
            'SELECT f.path, f.id FROM files AS f '
            . 'WHERE left(f.path, :len) = :prefix '
            . 'AND EXISTS (SELECT 1 FROM file_lines AS l WHERE l.file_path = f.path) '
            . 'ORDER BY f.path COLLATE "' . Search::COLLATION . '"'
        );
        $stmt->execute(['len' => mb_strlen($prefix, 'UTF-8'), 'prefix' => $prefix]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $path = (string) $row['path'];
            if (Paths::relative($path) === null) {
                continue;
            }
            $rows[] = [
                'path' => $path,
                'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : null,
            ];
        }
        return $rows;
    }

    /** @return string[] Logical paths in natural chapter order. */
    public static function chapters(string $logical): array
    {
        return array_column(self::chapterRows($logical), 'path');
    }

    /** @return string[] */
    public static function content(string $logical): array
    {
        $absolute = Paths::absolute($logical);
        if ($absolute === null) {
            return [];
        }
        $handle = @fopen($absolute, 'rb');
        if ($handle === false) {
            return [];
        }
        try {
            $paragraphs = Text::paragraphs($handle);
            return feof($handle) ? $paragraphs : [];
        } finally {
            fclose($handle);
        }
    }

    public static function url(string $logical, ?int $id = null, string $inode = ''): string
    {
        if ($id !== null && $id > 0) {
            return 'reader.php?id=' . $id;
        }
        if ($inode !== '' && ctype_digit($inode)) {
            return 'reader.php?inode=' . rawurlencode($inode);
        }
        return 'reader.php?file=' . rawurlencode($logical);
    }
}
