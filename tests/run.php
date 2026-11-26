<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    $path = $file->getPathname();
    if (!$file->isFile() || $file->getExtension() !== 'php'
        || str_contains($path, '/vendor/') || str_contains($path, '/.git/')) {
        continue;
    }
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        $failures++;
        fwrite(STDERR, implode("\n", $output) . "\n");
    } else {
        echo 'PASS lint ', substr($path, strlen($root) + 1), "\n";
    }
}

if ($failures > 0) {
    printf("\n%d lint failure(s)\n", $failures);
    exit(1);
}

if (in_array('--lint-only', $argv, true)) {
    exit(0);
}
if (!extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "Integration tests require the pdo_pgsql extension.\n");
    exit(1);
}
require __DIR__ . '/smoke.php';
