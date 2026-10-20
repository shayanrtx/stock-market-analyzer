<?php

declare(strict_types=1);

/*
 * CLI cron entry point. Schedule it every minute:
 *   * * * * * /usr/local/bin/php /home/USER/public_html/app/bin/cron.php >/dev/null 2>&1
 * Runs one live-monitoring cycle (only during market hours), links messenger accounts and housekeeping.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

$app = Sabadgardan\App::create(dirname(__DIR__));
if (!$app->installed()) {
    fwrite(STDERR, "Not installed yet: open the site in a browser to run the installer.\n");
    exit(1);
}
$migrations = new Sabadgardan\Core\Migrations($app->db());
if ($migrations->pending()) {
    $migrations->migrate();
}
$result = Sabadgardan\Http\Controllers\CronController::runJobs($app);
if (in_array('-v', $argv, true)) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
}
