<?php
declare(strict_types=1);

namespace TxtGrep;

use PDO;
use RuntimeException;
use Throwable;

/** Incremental chapter indexer. One DB row per line, one transaction per file. */
final class Indexer
{
    private const BATCH_SIZE = 1000;
    private const SEEN_BATCH_SIZE = 500;
    private const LOCK_KEY = 1415074887; // Stable database-wide key for txtgrep indexing.

    /**
     * @param string[] $books Empty means every book in the corpus.
     * @return array<string, int|float|string[]>
     */
    public function run(bool $full = false, bool $hashCheck = false, array $books = []): array
    {
        $pdo = Database::get();
        if (!$pdo->query('SELECT pg_try_advisory_lock(' . self::LOCK_KEY . ')')->fetchColumn()) {
            throw new RuntimeException('Another indexing run is already in progress.');
        }

        try {
            return $this->index($full, $hashCheck, $books);
        } finally {
            $pdo->query('SELECT pg_advisory_unlock(' . self::LOCK_KEY . ')');
        }
    }

    private function index(bool $full, bool $hashCheck, array $books): array
    {
        clearstatcache(true);
        $config = Config::get();
        $corpus = realpath($config->corpus());
        if ($corpus === false) {
            throw new RuntimeException('Cannot resolve corpus directory.');
        }
        $entries = @scandir($corpus);
        if ($entries === false) {
            throw new RuntimeException('Cannot scan corpus directory.');
        }
        $available = array_values(array_filter($entries, static function (string $entry) use ($corpus): bool {
            if ($entry[0] === '.') {
                return false;
            }
            $path = $corpus . '/' . $entry;
            return is_dir($path) || (is_link($path) && !file_exists($path));
        }));
        usort($available, 'strnatcmp');

        if ($books !== []) {
            $unknown = array_values(array_diff($books, $available));
            if ($unknown !== []) {
                throw new RuntimeException('找不到書目：' . implode(', ', $unknown));
            }
            $selected = array_values(array_filter($available, static fn (string $b): bool => in_array($b, $books, true)));
        } else {
            $selected = $available;
        }
        if ($selected === []) {
            throw new RuntimeException("No book directories found under {$corpus}");
        }

        $run = bin2hex(random_bytes(8));
        $startedAt = hrtime(true);
        $indexedFiles = 0;
        $indexedLines = 0;
        $skippedFiles = 0;
        $failedFiles = 0;
        $failedBooks = [];

        $root = Paths::prefix() . '/';
        $existingStmt = Database::get()->prepare(
            'SELECT path, size, mtime, inode, hash FROM files WHERE left(path, :len) = :root'
        );
        $existingStmt->execute(['len' => mb_strlen($root, 'UTF-8'), 'root' => $root]);
        $existingByPath = [];
        foreach ($existingStmt->fetchAll() as $row) {
            $existingByPath[(string) $row['path']] = $row;
        }

        $seenUpdates = [];
        $preserve = Database::get()->prepare('UPDATE files SET seen_run = :run WHERE path = :path');
        $upsert = Database::get()->prepare(
            'INSERT INTO files (path, size, mtime, inode, hash, seen_run, indexed_at)
             VALUES (:path, :size, :mtime, :inode, :hash, :run, now())
             ON CONFLICT (path) DO UPDATE SET
                 size = EXCLUDED.size, mtime = EXCLUDED.mtime, inode = EXCLUDED.inode,
                 hash = EXCLUDED.hash, seen_run = EXCLUDED.seen_run, indexed_at = now()'
        );
        $updateHash = Database::get()->prepare('UPDATE files SET hash = :hash WHERE path = :path');
        $deleteLines = Database::get()->prepare('DELETE FROM file_lines WHERE file_path = :path');

        foreach ($selected as $book) {
            $directory = $corpus . '/' . $book;
            $resolvedDirectory = realpath($directory);
            if ($resolvedDirectory !== false && !Paths::inside($resolvedDirectory, $corpus)) {
                continue;
            }
            $chapters = $resolvedDirectory === false ? false : @scandir($resolvedDirectory, SCANDIR_SORT_NONE);
            if ($chapters === false) {
                $failedBooks[] = $book;
                $failedFiles++;
                fwrite(STDERR, "Cannot list {$directory}\n");
                continue;
            }
            $chapters = array_values(array_filter(
                $chapters,
                static fn (string $name): bool => $name[0] !== '.' && str_ends_with($name, '.txt')
            ));
            usort($chapters, static fn (string $a, string $b): int => strnatcmp($a, $b));

            foreach ($chapters as $chapter) {
                $relative = Paths::logical($book, $chapter);
                $absolute = $resolvedDirectory . '/' . $chapter;
                if (is_link($absolute)) {
                    $resolved = realpath($absolute);
                    if ($resolved !== false && (!Paths::inside($resolved, $corpus) || !is_file($resolved))) {
                        continue;
                    }
                    $absolute = $resolved === false ? null : $resolved;
                } elseif (!is_file($absolute)) {
                    if (is_dir($absolute)) {
                        continue;
                    }
                    $absolute = null;
                }
                $stat = $absolute === null ? false : @stat($absolute);
                if ($stat === false) {
                    $preserve->execute(['run' => $run, 'path' => $relative]);
                    fwrite(STDERR, "Cannot stat {$relative}\n");
                    $failedFiles++;
                    continue;
                }
                ['size' => $size, 'mtime' => $mtime, 'ino' => $inode] = $stat;

                $existing = $existingByPath[$relative] ?? false;
                $unchanged = !$full && $existing !== false
                    && (int) $existing['size'] === $size
                    && (int) $existing['mtime'] === $mtime
                    && $existing['inode'] === (string) $inode;
                if ($unchanged && $hashCheck) {
                    $hash = @hash_file('sha256', $absolute);
                    $unchanged = $hash !== false && $hash === $existing['hash'];
                }
                if ($unchanged) {
                    $seenUpdates[$relative] = (string) $inode;
                    $skippedFiles++;
                    continue;
                }

                try {
                    Database::get()->beginTransaction();
                    $upsert->execute([
                        'path' => $relative,
                        'size' => $size,
                        'mtime' => $mtime,
                        'inode' => (string) $inode,
                        'hash' => null,
                        'run' => $run,
                    ]);
                    $deleteLines->execute(['path' => $relative]);

                    $handle = @fopen($absolute, 'rb');
                    if ($handle === false) {
                        throw new RuntimeException("Cannot open {$relative}");
                    }
                    $lineNumber = 0;
                    $batch = [];
                    try {
                        if (!self::sameSnapshot($stat, fstat($handle))) {
                            throw new RuntimeException("File changed before reading {$relative}");
                        }
                        $hash = hash_init('sha256');
                        foreach (Text::streamLines($handle, $hash) as $line) {
                            $lineNumber++;
                            $batch[] = [$lineNumber, Text::scrub($line)];
                            if (count($batch) >= self::BATCH_SIZE) {
                                self::insertBatch($relative, $batch);
                                $batch = [];
                            }
                        }
                        if (!feof($handle)) {
                            throw new RuntimeException("Cannot finish reading {$relative}");
                        }
                        clearstatcache(true);
                        if (!self::sameSnapshot($stat, fstat($handle))
                            || realpath($directory . '/' . $chapter) !== $absolute
                            || !self::sameSnapshot($stat, @stat($absolute))) {
                            throw new RuntimeException("File changed during reading {$relative}");
                        }
                    } finally {
                        fclose($handle);
                    }
                    self::insertBatch($relative, $batch);
                    $updateHash->execute(['hash' => hash_final($hash), 'path' => $relative]);
                    Database::get()->commit();
                    $indexedLines += $lineNumber;
                    $indexedFiles++;
                } catch (Throwable $error) {
                    if (Database::get()->inTransaction()) {
                        Database::get()->rollBack();
                    }
                    // Keep the previous index for this file instead of dropping it.
                    $preserve->execute(['run' => $run, 'path' => $relative]);
                    fwrite(STDERR, "Failed {$relative}: " . $error->getMessage() . "\n");
                    $failedFiles++;
                }
            }
            fwrite(STDERR, "Done: {$book}\n");
        }

        self::markSeenBatch($seenUpdates, $run);
        $removedFiles = self::deleteStale($run, $selected, $failedBooks, $books === []);
        $seconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        return [
            'books' => $selected,
            'indexed_files' => $indexedFiles,
            'indexed_lines' => $indexedLines,
            'skipped_files' => $skippedFiles,
            'failed_files' => $failedFiles,
            'removed_files' => $removedFiles,
            'seconds' => $seconds,
        ];
    }

    private static function sameSnapshot(array $expected, array|false $actual): bool
    {
        if ($actual === false) {
            return false;
        }
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
            if ($expected[$field] !== $actual[$field]) {
                return false;
            }
        }
        return true;
    }

    /** @param array<int, array{0: int, 1: string}> $batch */
    private static function insertBatch(string $path, array $batch): void
    {
        if ($batch === []) {
            return;
        }
        $tuples = [];
        $params = [];
        foreach ($batch as $index => [$lineNumber, $content]) {
            $pathParam = ':p' . $index;
            $lineParam = ':n' . $index;
            $contentParam = ':c' . $index;
            $tuples[] = "({$pathParam}, {$lineParam}, {$contentParam})";
            $params[$pathParam] = $path;
            $params[$lineParam] = $lineNumber;
            $params[$contentParam] = $content;
        }
        $stmt = Database::get()->prepare(
            'INSERT INTO file_lines (file_path, line_number, content) VALUES ' . implode(', ', $tuples)
        );
        $stmt->execute($params);
    }

    /** @param array<string, string> $updates logical path => inode */
    private static function markSeenBatch(array $updates, string $run): void
    {
        foreach (array_chunk($updates, self::SEEN_BATCH_SIZE, true) as $chunk) {
            $tuples = [];
            $params = ['run' => $run];
            $index = 0;
            foreach ($chunk as $path => $inode) {
                $pathParam = ':p' . $index;
                $inodeParam = ':i' . $index;
                $tuples[] = "({$pathParam}, {$inodeParam})";
                $params[$pathParam] = $path;
                $params[$inodeParam] = $inode;
                $index++;
            }
            Database::get()->prepare(
                'UPDATE files AS f SET seen_run = :run, inode = v.inode '
                . 'FROM (VALUES ' . implode(', ', $tuples) . ') AS v(path, inode) '
                . 'WHERE f.path = v.path'
            )->execute($params);
        }
    }

    /** Delete stale rows only within the current prefix and successfully scanned scope. */
    private static function deleteStale(string $run, array $selected, array $failedBooks, bool $allBooks): int
    {
        $root = Paths::prefix() . '/';
        $prefixes = [];
        if ($allBooks && $failedBooks === []) {
            $prefixes[] = $root;
        } else {
            foreach (array_diff($selected, $failedBooks) as $book) {
                $prefixes[] = $root . $book . '/';
            }
        }

        $stmt = Database::get()->prepare(
            'DELETE FROM files WHERE left(path, :len) = :prefix AND seen_run IS DISTINCT FROM :run'
        );
        $removed = 0;
        foreach ($prefixes as $prefix) {
            $stmt->execute(['len' => mb_strlen($prefix, 'UTF-8'), 'prefix' => $prefix, 'run' => $run]);
            $removed += $stmt->rowCount();
        }
        return $removed;
    }
}
