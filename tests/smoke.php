<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use TxtGrep\Config;
use TxtGrep\Database;
use TxtGrep\Paths;
use TxtGrep\Reader;
use TxtGrep\Search;
use TxtGrep\SearchValidationException;
use TxtGrep\Text;

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail === '' ? '' : " ({$detail})");
}

/** Escape POSIX ARE metacharacters without touching multibyte characters. */
function posixLiteral(string $value): string
{
    $special = ['\\', '.', '^', '$', '|', '?', '*', '+', '(', ')', '{', '}', '[', ']'];
    $out = '';
    foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
        $out .= in_array($character, $special, true) ? '\\' . $character : $character;
    }
    return $out;
}

$collation = Search::COLLATION;
$order = Database::get()->query(
    "SELECT string_agg(v, '|' ORDER BY v COLLATE \"{$collation}\")
     FROM (VALUES ('第10章.txt'), ('第2章.txt'), ('第1章.txt')) AS t(v)"
)->fetchColumn();
check('ICU natural collation', $order === '第1章.txt|第2章.txt|第10章.txt', (string) $order);

$fileCount = (int) Database::get()->query('SELECT count(*) FROM files')->fetchColumn();
$lineCount = (int) Database::get()->query('SELECT count(*) FROM file_lines')->fetchColumn();
check('files indexed', $fileCount > 0, (string) $fileCount);
check('lines indexed', $lineCount > 1000, (string) $lineCount);

$books = Search::availableBooks();
check('books discovered', $books !== [], implode(', ', $books));

// Derive a literal from the corpus so the test works with any book collection.
$literal = '';
$samples = Database::get()->query('SELECT content FROM file_lines ORDER BY file_path LIMIT 100');
while (($sample = $samples->fetchColumn()) !== false) {
    if (preg_match('/[\p{Han}\p{L}\p{N}]{4}/u', (string) $sample, $matches) === 1) {
        $literal = $matches[0];
        break;
    }
}
check('sample literal found', $literal !== '', $literal);

if ($literal !== '') {
    $pattern = posixLiteral($literal);
    $found = Search::run($pattern, ['*'], true, 3);
    check('search returns rows', $found['rows'] !== [], (string) count($found['rows']));
    check('limit respected', count($found['rows']) <= 3 && (!$found['truncated'] || count($found['rows']) === 3));

    $prefix = Paths::prefix() . '/';
    $shapeOk = true;
    foreach ($found['rows'] as $row) {
        $shapeOk = $shapeOk
            && str_starts_with((string) $row['file_path'], $prefix)
            && (int) $row['line_number'] >= 1
            && $row['inode'] !== null;
    }
    check('row shape (path / line / inode)', $shapeOk);

    if ($books !== []) {
        $scoped = Search::run($pattern, [$books[0]], true, 3);
        $scopedOk = true;
        foreach ($scoped['rows'] as $row) {
            $scopedOk = $scopedOk && str_starts_with((string) $row['file_path'], $prefix . $books[0] . '/');
        }
        check('book filter', $scoped['rows'] !== [] && $scopedOk, (string) count($scoped['rows']));
    }
}

$missing = Search::run('完全不會出現的字串zzzzqqqq', ['*'], true, 10);
check('no-match query is empty', $missing['rows'] === []);

try {
    Search::validatePatterns(['(*LIMIT_MATCH=10)x'], true);
    check('invalid regex rejected', false);
} catch (SearchValidationException $error) {
    check('invalid regex rejected', true);
}

if ($literal !== '') {
    $highlights = Search::matchOffsets($literal, [$literal], true);
    check(
        'PCRE byte offsets',
        count($highlights) === 1 && $highlights[0][0] === $literal && $highlights[0][1] === 0
    );
}

check('path traversal rejected', Paths::absolute(Paths::prefix() . '/../outside.txt') === null);
check('text lines split', Text::lines("a\r\nb\n\rc\n") === ['a', 'b', 'c']);

if ($fileCount > 0) {
    $samplePath = (string) Database::get()->query('SELECT path FROM files ORDER BY path LIMIT 1')->fetchColumn();
    check('reader resolves logical path', Reader::resolve($samplePath) === $samplePath);
    check('reader has content', Reader::content($samplePath) !== []);
    check('reader chapter list', in_array($samplePath, Reader::chapters($samplePath), true));
}

printf("\n%d failure(s)\n", $failures);
exit($failures === 0 ? 0 : 1);
