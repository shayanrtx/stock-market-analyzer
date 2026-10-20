<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;
use Sabadgardan\Live\Rules\RuleRegistry;
use Sabadgardan\Signals\SignalTracker;

final class LiveController extends Controller
{
    public function show(Request $q): Response
    {
        return $this->view($q, 'live/show', ['title' => 'رصد زنده', 'nav' => 'live', 'tour' => true,
            'rules' => RuleRegistry::defaults(), 'cronOk' => $this->cronRecent(),
            'interval' => (int) $this->app->config->get('live.interval', 45)]);
    }

    public function signals(Request $q): Response
    {
        $uid = $this->userId();
        $codes = [];
        foreach ($this->app->portfolios()->monitoredSymbols() as $code => $who) {
            if (in_array($uid, $who['holders'], true) || in_array($uid, $who['watchers'], true)) {
                $codes[] = (string) $code;
            }
        }
        $rows = [];
        if ($codes) {
            $in = implode(',', array_fill(0, count($codes), '?'));
            $rows = $this->app->db()->all("SELECT * FROM signals WHERE ins_code IN ($in) ORDER BY id DESC LIMIT 200", $codes);
        }
        $bundles = $rows ? $this->app->market()->bundles(array_values(array_unique(array_column($rows, 'ins_code'))), ['history']) : [];
        foreach ($rows as &$row) {
            $raw = $bundles[$row['ins_code']]->raw ?? null;
            $row['outcome'] = $raw && !$raw->isEmpty() ? SignalTracker::outcome($row, $raw) : null;
            $row['reasons'] = json_decode((string) $row['reasons'], true) ?: [];
            $row['class'] = $this->app->market()->instrument((string) $row['ins_code'])?->assetClass();
        }
        unset($row);
        $tracked = array_values(array_filter($rows, static fn ($r) => $r['outcome'] !== null));
        return $this->view($q, 'live/signals', ['title' => 'سیگنال‌ها', 'signals' => $rows,
            'summary' => SignalTracker::summary($tracked), 'nav' => 'signals', 'tour' => (bool) $rows]);
    }

    private function cronRecent(): bool
    {
        $last = $this->app->settings()->system('last_cron_at');
        return $last !== '' && time() - strtotime($last) < 600;
    }
}
