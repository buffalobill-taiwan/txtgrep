<?php
declare(strict_types=1);

// Works with or without Composer. The fallback loader is always registered so
// the project also works when Composer is installed without an autoload dump.
$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

if (!defined('TXTGREP_FALLBACK_AUTOLOADER')) {
    define('TXTGREP_FALLBACK_AUTOLOADER', true);
    spl_autoload_register(static function (string $class): void {
        $prefix = 'TxtGrep\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}
