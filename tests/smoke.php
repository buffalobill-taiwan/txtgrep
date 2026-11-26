<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use TxtGrep\Indexer;
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

$root = sys_get_temp_dir() . '/txtgrep-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
register_shutdown_function(static function () use ($root): void {
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        $path = $entry->getPathname();
        $entry->isDir() && !$entry->isLink() ? rmdir($path) : unlink($path);
    }
    rmdir($root);
});
mkdir("$root/corpus");
mkdir("$root/corpus/甲");
mkdir("$root/corpus/乙");
mkdir("$root/external");
file_put_contents("$root/external/private.txt", 'outside');
file_put_contents("$root/corpus/甲/第2章.txt", str_repeat('a', 8191) . "\r\n甲詞\r乙詞\n\n末行");
file_put_contents("$root/corpus/乙/第10章.txt", '甲詞');
symlink('../甲/第2章.txt', "$root/corpus/乙/alias.txt");
symlink("$root/external", "$root/corpus/外部");
symlink("$root/external/private.txt", "$root/corpus/甲/outside.txt");
putenv("TXTGREP_CORPUS=$root/corpus");
putenv('TXTGREP_PATH_PREFIX=fixture');

$pdo = Database::get();
$schema = 'txtgrep_test_' . bin2hex(random_bytes(8));
$pdo->exec("CREATE SCHEMA $schema");
try {
    $pdo->exec("SET search_path TO $schema");
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
    $pdo->exec("INSERT INTO files (path, size, mtime) VALUES
        ('other/甲/chapter.txt', 0, 0),
        ('fixture/甲/outside.txt', 0, 0),
        ('fixture/外部/private.txt', 0, 0)");
    $pdo->exec("INSERT INTO file_lines (file_path, line_number, content)
        VALUES ('other/甲/chapter.txt', 1, '甲詞')");
    $indexer = new Indexer();
    $first = $indexer->run();
    check('initial index accepts internal aliases and removes escaped rows',
        $first['indexed_files'] === 3 && $first['removed_files'] === 2);
    check('files have stable ids', (int) $pdo->query('SELECT count(*) FROM files WHERE id IS NULL')->fetchColumn() === 0);
    check('incremental run skips unchanged files', $indexer->run()['skipped_files'] === 3);
    $logical = 'fixture/甲/第2章.txt';
    $chapterId = Reader::id($logical);
    check('reader exposes and resolves stable id',
        $chapterId !== null && str_contains(Reader::url($logical, $chapterId), '?id=')
        && Reader::resolve('', '', (string) $chapterId) === $logical);
    $matches = Search::run('甲詞', ['*'], true, 10)['rows'];
    check('wildcard search stays within configured prefix', count($matches) === 3);
    $scoped = Search::run('甲詞', ['甲'], true, 10)['rows'];
    check('book filter and cross-buffer CRLF', count($scoped) === 1 && (int) $scoped[0]['line_number'] === 2);
    check('separate CR-delimited terms do not match together',
        Search::run('甲詞 乙詞', ['*'], true, 10)['rows'] === []);
    $last = Search::run('末行', ['甲'], true, 10)['rows'];
    check('blank line counted and unterminated line indexed', (int) ($last[0]['line_number'] ?? 0) === 5);
    check('reader uses same line boundaries', Reader::content($logical) === [str_repeat('a', 8191), '甲詞', '乙詞', '末行']);
    check('reader keeps an internal alias in chapter navigation',
        in_array('fixture/乙/alias.txt', Reader::chapters('fixture/乙/alias.txt'), true));
    $chapterRows = Reader::chapterRows('fixture/乙/alias.txt');
    check('chapter rows include stable ids', count($chapterRows) === 2
        && $chapterRows[0]['id'] !== null && $chapterRows[1]['id'] !== null);
    check('reader rejects external chapters', Reader::resolve('fixture/甲/outside.txt') === null);
    check('natural chapter order', $pdo->query("SELECT string_agg(v, '|' ORDER BY v COLLATE txtgrep_natural)
        FROM (VALUES ('第10章'), ('第2章')) AS t(v)")->fetchColumn() === '第2章|第10章');
    check('result cap', Search::run('甲詞', ['*'], true, 1)['truncated']);
    try {
        Search::validatePatterns(['['], true);
        check('invalid regex rejected', false);
    } catch (SearchValidationException $error) {
        check('invalid regex rejected', true);
    }
    check('UTF-8 byte offsets', Search::matchOffsets('甲乙', ['乙'], true) === [['乙', 3]]);
    unlink("$root/corpus/乙/第10章.txt");
    symlink('temporarily-missing.txt', "$root/corpus/乙/第10章.txt");
    check('unresolved chapter preserves old row', $indexer->run(false, false, ['乙'])['failed_files'] === 1
        && count(Search::run('甲詞', ['乙'], true, 10)['rows']) === 2);
    unlink("$root/corpus/乙/第10章.txt");
    check('scoped cleanup removes deleted chapter only', $indexer->run(false, false, ['乙'])['removed_files'] === 1
        && count(Search::run('甲詞', ['*'], true, 10)['rows']) === 2);
} finally {
    $pdo->exec('RESET search_path');
    $pdo->exec("DROP SCHEMA $schema CASCADE");
}

printf("\n%d failure(s)\n", $failures);
exit($failures === 0 ? 0 : 1);
