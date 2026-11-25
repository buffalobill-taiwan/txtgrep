#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use TxtGrep\Config;
use TxtGrep\Database;

$schema = file_get_contents(dirname(__DIR__) . '/sql/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "Cannot read sql/schema.sql\n");
    exit(1);
}

// Strip -- comments and split on semicolons. The shipped schema has no
// semicolons or comment markers inside string literals.
$schema = preg_replace('/--.*$/m', '', $schema) ?? '';
$statements = array_values(array_filter(array_map('trim', explode(';', $schema))));

$pdo = Database::get();
foreach ($statements as $statement) {
    $pdo->exec($statement);
}

printf("Schema ready (%d statement(s)) in %s\n", count($statements), Config::get()->dsn());
