<?php

declare(strict_types=1);

namespace Sabadgardan\Http\Controllers;

use Sabadgardan\Http\Request;
use Sabadgardan\Http\Response;
use Sabadgardan\Live\Rules\RuleRegistry;

final class LiveController extends Controller
{
    public function show(Request $q): Response
    {
        return $this->view($q, 'live/show', ['title' => 'رصد زنده', 'nav' => 'live', 'tour' => true,
            'rules' => RuleRegistry::defaults(), 'cronOk' => $this->cronRecent()]);
    }

    public function signals(Request $q): Response
    {
        $uid = $this->userId();
        $codes = [];
        foreach ($this->app->portfolios()->monitoredSymbols() as $code => $who) {
            if (in_array($uid, $who['holders'], true) || in_array($uid, $who['watchers'], true)) {
                $codes[] = $code;
            }
        }
        $rows = [];
        if ($codes) {
            $in = implode(',', array_fill(0, count($codes), '?'));
            $rows = $this->app->db()->all("SELECT * FROM signals WHERE ins_code IN ($in) ORDER BY id DESC LIMIT 200", $codes);
        }
        return $this->view($q, 'live/signals', ['title' => 'سیگنال‌ها', 'signals' => $rows, 'nav' => 'signals']);
    }

    private function cronRecent(): bool
    {
        $last = $this->app->settings()->system('last_cron_at');
        return $last !== '' && time() - strtotime($last) < 600;
    }
}
