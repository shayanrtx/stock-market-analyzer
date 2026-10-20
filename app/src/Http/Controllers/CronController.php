<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\Core\Settings;
use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;

/**
 * HTTP cron endpoint for hosts where a CLI cron is inconvenient:
 * ``wget -q -O- "https://example.com/cron?token=..."`` every minute.
 */
final class CronController extends Controller
{
    public function run(Request $q): Response
    {
        $token = (string) $this->app->config->get('cron_token');
        if ($token === '' || !hash_equals($token, $q->query('token'))) {
            return Response::json(['error' => 'توکن نامعتبر'], 403);
        }
        return Response::json(self::runJobs($this->app));
    }

    /** Shared by the HTTP endpoint and bin/cron.php. @return array<string, mixed> */
    public static function runJobs(\Sabadgardan\App $app): array
    {
        @set_time_limit(120);
        $result = ['live' => $app->live()->tick(), 'linked' => 0];
        try {
            $result['linked'] = $app->notifier()->pollUpdates();
        } catch (\Throwable $e) {
            $result['notify_error'] = $e->getMessage();
        }
        if (random_int(1, 60) === 1) {  // occasional housekeeping
            $app->cache()->prune();
            $app->audit()->prune();
            $app->db()->run('DELETE FROM login_attempts WHERE attempted_at < ?', [time() - 86400]);
        }
        $app->settings()->set(Settings::SYSTEM, 'last_cron_at', date('Y-m-d H:i:s'));
        $app->settings()->set(Settings::SYSTEM, 'last_cron_runner', (PHP_SAPI === 'cli' ? 'CLI' : 'HTTP') . ' · PHP ' . PHP_VERSION);
        return $result;
    }
}
