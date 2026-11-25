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

    /**
     * @param string[] $books Empty means every book in the corpus.
     * @return array<string, int|float|string[]>
     */
    public function run(bool $full = false, bool $hashCheck = false, array $books = []): array
    {
        $config = Config::get();
        $corpus = $config->corpus();
        $available = Search::availableBooks();

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

        $findFile = Database::get()->prepare('SELECT size, mtime, hash FROM files WHERE path = :path');
        $markSeen = Database::get()->prepare('UPDATE files SET seen_run = :run, inode = :inode WHERE path = :path');
        $upsert = Database::get()->prepare(
            'INSERT INTO files (path, size, mtime, inode, hash, seen_run, indexed_at)
             VALUES (:path, :size, :mtime, :inode, :hash, :run, now())
             ON CONFLICT (path) DO UPDATE SET
                 size = EXCLUDED.size, mtime = EXCLUDED.mtime, inode = EXCLUDED.inode,
                 hash = EXCLUDED.hash, seen_run = EXCLUDED.seen_run, indexed_at = now()'
        );
        $deleteLines = Database::get()->prepare('DELETE FROM file_lines WHERE file_path = :path');

        foreach ($selected as $book) {
            $directory = $corpus . '/' . $book;
            $chapters = scandir($directory, SCANDIR_SORT_NONE);
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
                $absolute = $directory . '/' . $chapter;
                clearstatcache(true, $absolute);
                $size = filesize($absolute);
                $mtime = filemtime($absolute);
                $inode = fileinode($absolute);
                if ($size === false || $mtime === false || $inode === false) {
                    fwrite(STDERR, "Cannot stat {$relative}\n");
                    $failedFiles++;
                    continue;
                }

                $findFile->execute(['path' => $relative]);
                $existing = $findFile->fetch();
                $unchanged = !$full && $existing !== false
                    && (int) $existing['size'] === $size
                    && (int) $existing['mtime'] === $mtime;
                if ($unchanged && $hashCheck && is_string($existing['hash']) && $existing['hash'] !== '') {
                    $unchanged = hash_file('sha256', $absolute) === $existing['hash'];
                }
                if ($unchanged) {
                    $markSeen->execute(['run' => $run, 'inode' => (string) $inode, 'path' => $relative]);
                    $skippedFiles++;
                    continue;
                }

                $hash = hash_file('sha256', $absolute);
                if ($hash === false) {
                    fwrite(STDERR, "Cannot hash {$relative}\n");
                    $failedFiles++;
                    continue;
                }

                try {
                    Database::get()->beginTransaction();
                    $upsert->execute([
                        'path' => $relative,
                        'size' => $size,
                        'mtime' => $mtime,
                        'inode' => (string) $inode,
                        'hash' => $hash,
                        'run' => $run,
                    ]);
                    $deleteLines->execute(['path' => $relative]);

                    $handle = fopen($absolute, 'rb');
                    if ($handle === false) {
                        throw new RuntimeException("Cannot open {$relative}");
                    }
                    $lineNumber = 0;
                    $batch = [];
                    while (($line = fgets($handle)) !== false) {
                        $lineNumber++;
                        $batch[] = [$lineNumber, Text::scrub(rtrim($line, "\r\n"))];
                        if (count($batch) >= self::BATCH_SIZE) {
                            self::insertBatch($relative, $batch);
                            $indexedLines += count($batch);
                            $batch = [];
                        }
                    }
                    $complete = feof($handle);
                    fclose($handle);
                    if (!$complete) {
                        throw new RuntimeException("Cannot finish reading {$relative}");
                    }
                    self::insertBatch($relative, $batch);
                    $indexedLines += count($batch);
                    Database::get()->commit();
                    $indexedFiles++;
                } catch (Throwable $error) {
                    if (Database::get()->inTransaction()) {
                        Database::get()->rollBack();
                    }
                    // Keep the previous index for this file instead of dropping it.
                    $markSeen->execute(['run' => $run, 'inode' => (string) $inode, 'path' => $relative]);
                    fwrite(STDERR, "Failed {$relative}: " . $error->getMessage() . "\n");
                    $failedFiles++;
                }
            }
            fwrite(STDERR, "Done: {$book}\n");
        }

        $removedFiles = self::deleteStale($run, $books === [] ? [] : $selected, $failedBooks);
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

    /**
     * A full run may remove books that no longer exist. A scoped run must never
     * delete rows belonging to other books.
     *
     * @param string[] $scopedBooks Books explicitly requested on the command line.
     * @param string[] $failedBooks Books that could not be scanned this run.
     */
    private static function deleteStale(string $run, array $scopedBooks, array $failedBooks): int
    {
        $pdo = Database::get();
        if ($scopedBooks === [] && $failedBooks === []) {
            $stmt = $pdo->prepare('DELETE FROM files WHERE seen_run IS DISTINCT FROM :run');
            $stmt->execute(['run' => $run]);
            return $stmt->rowCount();
        }

        $removed = 0;
        foreach ($scopedBooks as $book) {
            if (in_array($book, $failedBooks, true)) {
                continue;
            }
            $prefix = Paths::prefix() . '/' . $book . '/';
            $stmt = $pdo->prepare(
                'DELETE FROM files WHERE left(path, :len) = :prefix AND seen_run IS DISTINCT FROM :run'
            );
            $stmt->execute(['len' => mb_strlen($prefix, 'UTF-8'), 'prefix' => $prefix, 'run' => $run]);
            $removed += $stmt->rowCount();
        }
        return $removed;
    }
}
