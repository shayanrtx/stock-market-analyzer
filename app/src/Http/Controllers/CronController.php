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

    /**
     * Shared by the HTTP endpoint, bin/cron.php and the admin's test run. Each job is isolated: one
     * failing (e.g. the market API timing out) neither stops the others nor hides that cron ran.
     * ``$record`` = false for the admin's test run, so it does not pass for a working cron.
     *
     * @return array<string, mixed>
     */
    public static function runJobs(\Sabadgardan\App $app, bool $record = true): array
    {
        @set_time_limit(120);
        $result = ['live' => null, 'linked' => 0, 'backup' => null, 'errors' => []];
        $jobs = [
            'live' => static fn () => $app->live()->tick(),
            'linked' => static fn () => $app->notifier()->pollUpdates(),
            'backup' => static fn () => $app->backup()->due(time()) ? $app->backup()->create(time()) : null,
        ];
        if (random_int(1, 60) === 1) {  // occasional housekeeping
            $jobs['housekeeping'] = static function () use ($app): bool {
                $app->cache()->prune();
                $app->audit()->prune();
                $app->db()->run('DELETE FROM login_attempts WHERE attempted_at < ?', [time() - 86400]);
                $app->db()->run('DELETE FROM password_resets WHERE expires_at < ?', [time() - 7 * 86400]);
                (new \Sabadgardan\Alerts\DisclosureWatch($app->db(), $app->market(), $app->cache()))->prune(time());
                return true;
            };
        }
        foreach ($jobs as $name => $job) {
            try {
                $result[$name] = $job();
            } catch (\Throwable $e) {
                $result['errors'][$name] = $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
                error_log("[cron:$name] $e");
            }
        }
        if ($record) {
            $s = $app->settings();
            $s->set(Settings::SYSTEM, 'last_cron_at', date('Y-m-d H:i:s'));
            $s->set(Settings::SYSTEM, 'last_cron_runner', (PHP_SAPI === 'cli' ? 'CLI' : 'HTTP') . ' · PHP ' . PHP_VERSION);
            $s->set(Settings::SYSTEM, 'last_cron_error', implode(' | ', array_map(static fn ($k, $v) => "$k: $v",
                array_keys($result['errors']), $result['errors'])));
        }
        return $result;
    }
}
