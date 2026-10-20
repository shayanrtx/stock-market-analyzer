<?php

declare(strict_types=1);

/*
 * Zero-dependency test runner (shared hosts rarely have Composer/PHPUnit).
 * Usage: php tests/run.php [filter]
 * Discovers tests/*Test.php, runs every public method named test*, reports failures and exits non-zero.
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/TestCase.php';

// Any warning or notice is a bug: fail the test instead of printing it.
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    throw new ErrorException($msg, 0, $no, $file, $line);
});

$filter = $argv[1] ?? '';
$passed = $failed = 0;
$failures = [];
$start = microtime(true);

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require_once $file;
    $class = 'Tests\\' . basename($file, '.php');
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test') || ($filter !== '' && !str_contains("$class::$method", $filter))) {
            continue;
        }
        $case = new $class();
        try {
            $case->setUp();
            $case->$method();
            $passed++;
            echo '.';
        } catch (Throwable $e) {
            $failed++;
            echo 'F';
            $failures[] = sprintf("%s::%s\n  %s: %s\n  at %s:%d", $class, $method, get_class($e), $e->getMessage(),
                $e->getFile(), $e->getLine());
        }
    }
}

echo "\n\n";
foreach ($failures as $i => $f) {
    echo ($i + 1) . ") $f\n\n";
}
printf("%d passed, %d failed in %.2fs\n", $passed, $failed, microtime(true) - $start);
exit($failed ? 1 : 0);
