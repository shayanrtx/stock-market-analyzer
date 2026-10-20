<?php

declare(strict_types=1);

/*
 * CLI cron entry point. Schedule it every minute (cPanel → Cron Jobs, all fields "*"):
 *   /usr/local/bin/php /home/USER/public_html/bin/cron.php
 * No output redirection is needed (cPanel rejects ">/dev/null 2>&1" as command chaining): a normal
 * run prints nothing, so the host sends no e-mail. Problems are written to storage/logs/cron.log.
 * Add -v to see what one cycle did: php bin/cron.php -v
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$verbose = in_array('-v', $argv, true);
if (!$verbose) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', $root . '/storage/logs/cron.log');
}
if (PHP_VERSION_ID < 80100) {
    // The one message worth an e-mail: the host's CLI PHP is older than the site's.
    fwrite(STDERR, 'PHP ' . PHP_VERSION . " is too old for cron; use the PHP 8.1+ binary of your host, "
        . "e.g. /opt/cpanel/ea-php82/root/usr/bin/php or /opt/alt/php82/usr/bin/php\n");
    exit(1);
}
require $root . '/src/bootstrap.php';

try {
    $app = Sabadgardan\App::create($root);
    if (!$app->installed()) {
        fwrite(STDERR, "Not installed yet: open the site in a browser to run the installer.\n");
        exit(1);
    }
    $migrations = new Sabadgardan\Core\Migrations($app->db());
    if ($migrations->pending()) {
        $migrations->migrate();
    }
    $result = Sabadgardan\Http\Controllers\CronController::runJobs($app);
    if ($verbose) {
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
    }
} catch (Throwable $e) {
    error_log('[cron] ' . $e);
    if ($verbose) {
        fwrite(STDERR, $e . "\n");
    }
    exit(1);
}
