<?php
declare(strict_types=1);

namespace TxtGrep;

use PDO;
use PDOException;
use RuntimeException;

class SearchValidationException extends RuntimeException {}

/** Shared search logic for the web API and the CLI. */
final class Search
{
    public const COLLATION = 'txtgrep_natural';

    /** @return string[] */
    public static function splitTerms(string $keyword): array
    {
        $terms = preg_split('/\s+/u', trim($keyword), -1, PREG_SPLIT_NO_EMPTY);
        return $terms === false ? [] : array_values(array_unique($terms));
    }

    /** @return string[] */
    public static function availableBooks(): array
    {
        $dirs = glob(Config::get()->corpus() . '/*', GLOB_ONLYDIR | GLOB_ERR) ?: [];
        $books = array_values(array_map('basename', $dirs));
        usort($books, static fn (string $a, string $b): int => strnatcmp($a, $b));
        return $books;
    }

    /**
     * Accept "*", a book name, or a list of either. Logical prefixes such as
     * "txt/三國演義" are accepted for compatibility with existing links.
     *
     * @return string[] ['*'] or validated book names
     */
    public static function normalizeSources(string|array $sources): array
    {
        if (is_string($sources)) {
            $sources = [$sources];
        }
        $out = [];
        foreach ($sources as $source) {
            if (!is_string($source) || trim($source) === '') {
                throw new SearchValidationException('請選擇書目。');
            }
            $source = trim($source);
            if ($source === '*') {
                return ['*'];
            }
            $prefix = Paths::prefix() . '/';
            if (str_starts_with($source, $prefix)) {
                $source = substr($source, strlen($prefix));
            }
            if (str_contains($source, '/') || str_contains($source, '\\')
                || str_contains($source, "\0")) {
                throw new SearchValidationException('書目無效。');
            }
            $out[] = $source;
        }
        $out = array_values(array_unique($out));
        $books = self::availableBooks();
        foreach ($out as $source) {
            if (!in_array($source, $books, true)) {
                throw new SearchValidationException('書目無效。');
            }
        }
        return $out;
    }

    /** Compile patterns with PostgreSQL's POSIX ARE before scanning. */
    public static function validatePatterns(array $terms, bool $caseInsensitive): void
    {
        $operator = $caseInsensitive ? '~*' : '~';
        $stmt = Database::get()->prepare("SELECT CAST('' AS text) {$operator} CAST(:pattern AS text)");
        foreach ($terms as $term) {
            try {
                $stmt->execute(['pattern' => $term]);
            } catch (PDOException $error) {
                if (($error->errorInfo[0] ?? null) === '2201B') {
                    throw new SearchValidationException('正規表示式無效。', 0, $error);
                }
                throw $error;
            }
        }
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, truncated: bool, terms: array<int, string>}
     */
    public static function run(string $keyword, array $sources, bool $caseInsensitive, int $limit): array
    {
        $terms = self::splitTerms($keyword);
        if ($terms === []) {
            throw new SearchValidationException('請輸入關鍵字。');
        }
        self::validatePatterns($terms, $caseInsensitive);

        $prefix = Paths::prefix() . '/';
        $where = ['left(l.file_path, :root_len) = :root'];
        $params = ['root_len' => mb_strlen($prefix, 'UTF-8'), 'root' => $prefix];
        $operator = $caseInsensitive ? '~*' : '~';
        foreach ($terms as $index => $term) {
            $placeholder = ':t' . $index;
            $where[] = "l.content {$operator} {$placeholder}";
            $params[$placeholder] = $term;
        }
        if ($sources !== ['*']) {
            $filters = [];
            foreach ($sources as $index => $book) {
                $prefixParam = ':p' . $index;
                $lengthParam = ':l' . $index;
                $prefix = Paths::prefix() . '/' . $book . '/';
                $filters[] = "left(l.file_path, {$lengthParam}) = {$prefixParam}";
                $params[$prefixParam] = $prefix;
                $params[$lengthParam] = mb_strlen($prefix, 'UTF-8');
            }
            $where[] = '(' . implode(' OR ', $filters) . ')';
        }

        $limit = max(1, min($limit, 5000));
        $collation = self::COLLATION;
        $sql = 'SELECT l.file_path, l.line_number, l.content, f.inode '
            . 'FROM file_lines AS l JOIN files AS f ON f.path = l.file_path '
            . 'WHERE ' . implode(' AND ', $where) . ' '
            . "ORDER BY l.file_path COLLATE \"{$collation}\", l.line_number "
            . 'LIMIT ' . ($limit + 1);

        Database::get()->exec('SET statement_timeout = ' . Config::get()->timeoutMs());
        $stmt = Database::get()->prepare($sql);
        $stmt->execute($params);
        $all = $stmt->fetchAll();
        $truncated = count($all) > $limit;
        return [
            'rows' => $truncated ? array_slice($all, 0, $limit) : $all,
            'truncated' => $truncated,
            'terms' => $terms,
        ];
    }

    /**
     * Recompute byte offsets for the frontend highlighter on the returned rows
     * only. PCRE-incompatible patterns are left unhighlighted; the DB search
     * has already succeeded with PostgreSQL's regex syntax.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    public static function matchOffsets(string $text, array $terms, bool $caseInsensitive): array
    {
        $delimiter = chr(1);
        $flags = ($caseInsensitive ? 'i' : '') . 'u';
        $highlights = [];
        foreach ($terms as $term) {
            if (!is_string($term) || str_contains($term, $delimiter) || str_contains($term, "\0")) {
                continue;
            }
            $pattern = $delimiter . '(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=1000)' . $term . $delimiter . $flags;
            if (@preg_match($pattern, '') === false) {
                continue;
            }
            $count = preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
            if ($count === false || $count === 0) {
                continue;
            }
            array_push($highlights, ...$matches[0]);
        }
        usort($highlights, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
        return $highlights;
    }
}
