<?php

declare(strict_types=1);

namespace Tests;

use Sabadgardan\App;
use Sabadgardan\Http\Controllers\CronController;
use Sabadgardan\Live\LiveMonitor;
use Sabadgardan\Live\MarketClock;
use Sabadgardan\Portfolio\Transaction;

/** The monitor run through a simulated session of the synthetic market. */
final class LiveMonitorTest extends TestCase
{
    private static function tehran(string $time): int
    {
        return strtotime("$time UTC") - MarketClock::OFFSET;
    }

    /** @return array{0: App, 1: int} */
    private function session(string $until = '2026-10-20 12:25', int $step = 600): array
    {
        $this->now = self::tehran('2026-10-20 08:46');
        $app = $this->makeApp();
        $uid = $app->auth()->createUser('trader', 'password1', 'معامله‌گر', false);
        $pid = (int) $app->portfolios()->defaultPortfolio($uid)['id'];
        foreach (['فملی' => 5800, 'کمند' => 24000, 'شستا' => 1000] as $symbol => $price) {
            $inst = $app->market()->resolve($symbol);
            $app->portfolios()->saveTransaction($uid, $pid, new Transaction(null, 'buy', '2026-01-10', $inst->insCode, $inst->symbol, 1000, $price));
        }
        foreach (['فارس', 'وبملت', 'خودرو'] as $symbol) {
            $inst = $app->market()->resolve($symbol);
            $app->portfolios()->watch($uid, $inst->insCode, $inst->symbol);
        }
        for (; $this->now <= self::tehran($until); $this->now += $step) {
            $app->live()->tick(true);
        }
        return [$app, $uid];
    }

    public function testSessionRecordsInsightsAndStatesOnlyOnce(): void
    {
        [$app, $uid] = $this->session();
        $rows = $app->db()->all("SELECT * FROM live_insights WHERE day = '20261020'");
        $this->assertTrue(count($rows) > 0, 'a session of six symbols produces insights');

        $seen = [];
        foreach ($rows as $r) {
            if (in_array($r['rule'], LiveMonitor::ONCE_A_DAY, true)) {
                $key = "{$r['ins_code']}|{$r['rule']}|{$r['direction']}|{$r['severity']}";
                $this->assertFalse(isset($seen[$key]), "state insight repeated: {$r['symbol']} {$r['rule']}");
                $seen[$key] = true;
            }
        }
        $fund = $app->market()->resolve('کمند')->insCode;
        $this->assertSame(0, (int) $app->db()->value('SELECT COUNT(*) FROM live_insights WHERE ins_code = ?', [$fund]),
            'a fixed-income fund moves by accrual; its ticks are not events');
        $this->assertSame(0, (int) $app->db()->value('SELECT COUNT(*) FROM signals WHERE ins_code = ?', [$fund]));

        $state = $app->live()->state($uid);
        $this->assertSame(MarketClock::CONTINUOUS, $state['phase']);
        $this->assertSame(6, count($state['symbols']));
        $byCode = array_column($state['symbols'], null, 'ins_code');
        $this->assertSame('صندوق درآمد ثابت', $byCode[$fund]['class']);
        $this->assertTrue($byCode[$fund]['held']);
        $this->assertTrue($byCode[$fund]['price'] > 0);
    }

    public function testRequestDrivenTicksRespectTheInterval(): void
    {
        $this->now = self::tehran('2026-10-20 10:00');
        $app = $this->makeApp();
        $this->assertTrue($app->live()->tick()['ran']);
        $this->now += 10;
        $second = $app->live()->tick();
        $this->assertFalse($second['ran']);
        $this->assertSame('fresh', $second['reason']);
        $this->now += 60;
        $this->assertTrue($app->live()->tick()['ran']);
    }

    public function testClosedMarketDoesNotEvaluateRules(): void
    {
        $this->now = self::tehran('2026-10-22 10:00');  // Thursday
        $app = $this->makeApp();
        $r = $app->live()->tick(true);
        $this->assertSame(MarketClock::CLOSED, $r['phase']);
        $this->assertSame(0, $r['insights']);
    }

    public function testCronJobsRecordTheirRun(): void
    {
        $this->now = self::tehran('2026-10-20 10:00');
        $app = $this->makeApp();
        $result = CronController::runJobs($app);
        $this->assertTrue(array_key_exists('live', $result));
        $this->assertTrue($app->settings()->system('last_cron_at') !== '');
    }
}
