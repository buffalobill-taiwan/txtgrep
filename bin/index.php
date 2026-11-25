#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use TxtGrep\Config;
use TxtGrep\Indexer;

$usage = <<<'TXT'
Usage: php bin/index.php [options] [book ...]
  --full          rebuild every selected chapter even when unchanged
  --hash-check    compare sha256 before treating size/mtime as unchanged
  --help          show this help

With no book arguments, every directory under TXTGREP_CORPUS is indexed.
Removing a chapter or a book is detected at the end of the run.
TXT;

$full = false;
$hashCheck = false;
$books = [];
$arguments = array_slice($argv, 1);
foreach ($arguments as $argument) {
    if ($argument === '--full') {
        $full = true;
    } elseif ($argument === '--hash-check') {
        $hashCheck = true;
    } elseif ($argument === '--help' || $argument === '-h') {
        echo $usage, "\n";
        exit(0);
    } elseif (str_starts_with($argument, '-')) {
        fwrite(STDERR, "Unknown option: {$argument}\n{$usage}\n");
        exit(2);
    } else {
        $books[] = $argument;
    }
}

try {
    $stats = (new Indexer())->run($full, $hashCheck, $books);
} catch (Throwable $error) {
    fwrite(STDERR, 'Index failed: ' . $error->getMessage() . "\n");
    exit(1);
}

printf(
    "Indexed %d files (%d lines), skipped %d, failed %d, removed %d stale files in %.1fs\n",
    $stats['indexed_files'],
    $stats['indexed_lines'],
    $stats['skipped_files'],
    $stats['failed_files'],
    $stats['removed_files'],
    $stats['seconds']
);
printf("Corpus: %s\n", Config::get()->corpus());
exit($stats['failed_files'] > 0 ? 1 : 0);
