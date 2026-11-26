#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use TxtGrep\Config;
use TxtGrep\Paths;
use TxtGrep\Reader;
use TxtGrep\Search;
use TxtGrep\SearchValidationException;

$usage = <<<'TXT'
Usage: php bin/grep.php [options] PATTERN [book ...]
  -i                  case-insensitive (the web UI is always case-insensitive)
  --json              emit JSON objects instead of path:line:content
  --max-count N       global result cap (default: TXTGREP_LIMIT or 500)
  --limit N           alias for --max-count
  --help              show this help
  --                  treat the remaining arguments as pattern/books

Books may be a directory name under the corpus or txt/<book>.
Patterns use PostgreSQL POSIX ARE syntax (use \y instead of PCRE \b).
TXT;

$caseInsensitive = false;
$json = false;
$limit = Config::get()->limit();
$pattern = null;
$sources = [];
$endOfOptions = false;
$arguments = array_slice($argv, 1);

for ($index = 0; $index < count($arguments); $index++) {
    $argument = $arguments[$index];
    if ($endOfOptions) {
        if ($pattern === null) {
            $pattern = $argument;
        } else {
            $sources[] = $argument;
        }
        continue;
    }
    if ($argument === '--') {
        $endOfOptions = true;
    } elseif ($argument === '--help' || $argument === '-h') {
        echo $usage, "\n";
        exit(0);
    } elseif ($argument === '-i') {
        $caseInsensitive = true;
    } elseif ($argument === '--json') {
        $json = true;
    } elseif ($argument === '--max-count' || $argument === '--limit') {
        $value = $arguments[++$index] ?? null;
        if ($value === null || !ctype_digit($value) || (int) $value < 1) {
            fwrite(STDERR, "{$argument} needs a positive integer\n");
            exit(2);
        }
        $limit = (int) $value;
    } elseif (str_starts_with($argument, '--max-count=') || str_starts_with($argument, '--limit=')) {
        [, $value] = array_pad(explode('=', $argument, 2), 2, '');
        if (!ctype_digit($value) || (int) $value < 1) {
            fwrite(STDERR, "Result cap needs a positive integer\n");
            exit(2);
        }
        $limit = (int) $value;
    } elseif (str_starts_with($argument, '-')) {
        fwrite(STDERR, "Unknown option: {$argument}\n{$usage}\n");
        exit(2);
    } elseif ($pattern === null) {
        $pattern = $argument;
    } else {
        $sources[] = $argument;
    }
}

if ($pattern === null) {
    fwrite(STDERR, $usage . "\n");
    exit(2);
}
$delimiter = chr(1);
if (str_contains($pattern, $delimiter) || str_contains($pattern, "\0")) {
    fwrite(STDERR, "Pattern contains an unsupported control character.\n");
    exit(2);
}

try {
    $books = $sources === [] ? ['*'] : Search::normalizeSources($sources);
    $found = Search::run($pattern, $books, $caseInsensitive, $limit);
} catch (SearchValidationException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
} catch (PDOException $error) {
    fwrite(STDERR, 'Database error: ' . $error->getMessage() . "\n");
    exit(1);
}

if ($json) {
    $rows = [];
    foreach ($found['rows'] as $row) {
        $path = (string) $row['file_path'];
        $fileId = is_numeric($row['file_id'] ?? null) ? (int) $row['file_id'] : null;
        $inode = $row['inode'] === null ? '' : (string) $row['inode'];
        $rows[] = [
            'file' => $path,
            'book' => Paths::book($path),
            'url' => Reader::url($path, $fileId, $inode),
            'line' => (int) $row['line_number'],
            'text' => $row['content'],
            'file_id' => $fileId,
            'inode' => $inode,
            'matches' => Search::matchOffsets($row['content'], $found['terms'], $caseInsensitive),
        ];
    }
    echo json_encode($rows, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
} else {
    foreach ($found['rows'] as $row) {
        echo $row['file_path'], ':', $row['line_number'], ':', $row['content'], "\n";
    }
}
if ($found['truncated']) {
    fwrite(STDERR, "Results truncated to {$limit}.\n");
}
