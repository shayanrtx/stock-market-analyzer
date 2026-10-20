<?php

declare(strict_types=1);

/*
 * Shared bootstrap for the web front controller, CLI scripts and tests.
 * No Composer: a small PSR-4 autoloader maps Sabadgardan\Foo\Bar to src/Foo/Bar.php.
 */

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('این برنامه به PHP نسخه‌ی 8.1 یا جدیدتر نیاز دارد. نسخه‌ی فعلی: ' . PHP_VERSION);
}

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Sabadgardan\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set('Asia/Tehran');
mb_internal_encoding('UTF-8');
