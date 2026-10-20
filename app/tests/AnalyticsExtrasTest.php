<?php

declare(strict_types=1);

namespace Tests;

use Sabadgardan\Analysis\MarketRegime;
use Sabadgardan\Analysis\Stats;
use Sabadgardan\Market\AssetClass;
use Sabadgardan\Portfolio\Advisor;
use Sabadgardan\Portfolio\Returns;
use Sabadgardan\Portfolio\RiskAnalyzer;
use Sabadgardan\Portfolio\Transaction;
use Sabadgardan\Signals\SignalEngine;

/** Confidence intervals, portfolio returns, risk parity, market regime and the rules built on them. */
final class AnalyticsExtrasTest extends TestCase
{
    public function testWilsonInterval(): void
    {
        $ci = Stats::wilson(7, 10);
        $this->assertNear(0.3968, $ci['low'], 1e-3);
        $this->assertNear(0.8922, $ci['high'], 1e-3);
        $wide = Stats::wilson(70, 100);
        $this->assertTrue($wide['high'] - $wide['low'] < $ci['high'] - $ci['low'], 'more samples, narrower interval');
        $this->assertNear(0.0, Stats::wilson(0, 20)['low']);
        $this->assertNull(Stats::wilson(0, 0));
    }

    private static function tx(string $type, string $date, float $qty = 0, float $price = 0, float $amount = 0): Transaction
    {
        return new Transaction(null, $type, $date, $type === Transaction::DEPOSIT ? null : '1', 'نماد', $qty, $price, 0, $amount);
    }

    public function testTimeWeightedReturnIgnoresMoneyMovedInAndOut(): void
    {
        $txs = [
            self::tx(Transaction::DEPOSIT, '2026-01-01', amount: 5_000_000),     // cash in the account: not the holdings' return
            self::tx(Transaction::BUY, '2026-01-03', 10, 100),                  // 1000 in
            self::tx(Transaction::BUY, '2026-01-07', 10, 110),                  // 1100 more, at the new price
            self::tx(Transaction::DIVIDEND, '2026-01-09', amount: 100),         // income, part of the return
        ];
        $values = ['2026-01-03' => 1000.0, '2026-01-05' => 1100.0, '2026-01-07' => 2200.0, '2026-01-08' => 2420.0, '2026-01-09' => 2420.0];
        $index = Returns::twrIndex($values, $txs);
        $this->assertNear(100.0, $index['2026-01-03']);
        $this->assertNear(110.0, $index['2026-01-05']);
        $this->assertNear(110.0, $index['2026-01-07'], 1e-9, 'adding money at the market price is not a return');
        $this->assertNear(121.0, $index['2026-01-08']);
        $this->assertNear(121.0 * 2520 / 2420, $index['2026-01-09'], 1e-9, 'the dividend counts');
    }

    public function testMoneyWeightedReturn(): void
    {
        $this->assertNear(0.10, Returns::xirr([['2025-01-01', -1000.0], ['2026-01-01', 1100.0]]), 1e-6);
        // Two equal purchases a year apart, 21% on the first and 10% on the second, both worth 1100+1210 at the end.
        $r = Returns::xirr([['2024-01-01', -1000.0], ['2025-01-01', -1000.0], ['2026-01-01', 1210.0 + 1100.0]]);
        $this->assertNear(0.10, $r, 1e-3);
        $this->assertTrue(Returns::xirr([['2025-01-01', -1000.0], ['2026-01-01', 500.0]]) < -0.49, 'losses solve too');
        $this->assertNull(Returns::xirr([['2025-01-01', 1000.0]]));
        $this->assertNull(Returns::annualise(0.05, 30), 'a month is too short to annualise');
        $this->assertNear(0.21, Returns::annualise(0.4641, 730), 1e-3);
    }

    public function testShortPeriodsAreNotAnnualised(): void
    {
        $app = $this->makeApp();
        $uid = $app->auth()->createUser('newbie', 'password1', 'تازه‌کار', false);
        $pid = (int) $app->portfolios()->defaultPortfolio($uid)['id'];
        $inst = $app->market()->resolve('فملی');
        $raw = $app->market()->bundles([$inst->insCode], ['history'])[$inst->insCode]->raw;
        $i = $raw->count() - 15;   // three weeks ago
        $app->portfolios()->saveTransaction($uid, $pid, new Transaction(null, 'buy', $raw->dates[$i], $inst->insCode, $inst->symbol, 1000, $raw->close[$i]));
        $ret = $app->portfolioService()->performance($app->portfolios()->transactions($uid, $pid), $uid)['returns'];
        $this->assertNotNull($ret['twr']['total']);
        $this->assertNull($ret['twr']['annual']);
        $this->assertNull($ret['xirr'], 'a three-week XIRR would read as thousands of percent');
    }

    public function testRiskParityEqualisesRiskContributions(): void
    {
        $w = RiskAnalyzer::riskParity(['a' => ['a' => 1.0, 'b' => 0.0], 'b' => ['a' => 0.0, 'b' => 4.0]]);
        $this->assertNear(2 / 3, $w['a'], 1e-6, 'half the volatility, twice the weight');
        $this->assertNear(1 / 3, $w['b'], 1e-6);

        $cov = ['a' => ['a' => 0.04, 'b' => 0.012, 'c' => 0.0], 'b' => ['a' => 0.012, 'b' => 0.09, 'c' => 0.006],
                'c' => ['a' => 0.0, 'b' => 0.006, 'c' => 0.0225]];
        $w = RiskAnalyzer::riskParity($cov);
        $this->assertNear(1.0, array_sum($w));
        $contrib = [];
        foreach ($cov as $i => $row) {
            $contrib[$i] = $w[$i] * array_sum(array_map(static fn ($j) => $row[$j] * $w[$j], array_keys($row)));
        }
        $this->assertNear(max($contrib), min($contrib), 1e-6, 'every holding adds the same variance');
        $this->assertNull(RiskAnalyzer::riskParity(['a' => ['a' => 0.0]]));
    }

    /** @return array<string, float> */
    private static function index(callable $level, int $n = 320): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[date('Y-m-d', strtotime('2025-01-01 UTC') + $i * 86400)] = $level($i);
        }
        return $out;
    }

    public function testMarketRegime(): void
    {
        $this->assertSame(MarketRegime::BULL, MarketRegime::assess(self::index(static fn ($i) => 1000 + 3 * $i))->state);
        $this->assertSame(MarketRegime::BEAR, MarketRegime::assess(self::index(static fn ($i) => 3000 - 4 * $i))->state);
        $crash = MarketRegime::assess(self::index(static fn ($i) => $i < 300 ? 1000 + 3 * $i : 1900 * (1 - 0.015 * ($i - 299))));
        $this->assertSame(MarketRegime::BEAR, $crash->state, 'more than 20% off the high is a bear market');
        $narrow = MarketRegime::assess(self::index(static fn ($i) => 1000 + 3 * $i), 0.2);
        $this->assertSame(MarketRegime::NEUTRAL, $narrow->state, 'a rally few symbols join is not a bull market');
        $this->assertSame(MarketRegime::NEUTRAL, MarketRegime::assess(self::index(static fn ($i) => 1000.0, 100))->state);
        $this->assertSame('صعودی', MarketRegime::fromArray(MarketRegime::assess(self::index(static fn ($i) => 1000 + 3 * $i))->toArray())->label());
    }

    public function testSignalBarsRiseInABearMarketAndSmallSamplesDontPass(): void
    {
        $bear = MarketRegime::assess(self::index(static fn ($i) => 3000 - 4 * $i));
        $this->assertSame(['score' => SignalEngine::BEAR_MIN_SCORE, 'probability' => SignalEngine::BEAR_MIN_PROBABILITY],
            SignalEngine::thresholds($bear));
        $odds = ['expectancy_pct' => 2.0, 'win_rate' => 0.5, 'loss_rate' => 0.3, 'win_low' => 0.42];
        $this->assertTrue(SignalEngine::acceptable($odds, 2.0));
        $this->assertFalse(SignalEngine::acceptable($odds, 2.0, $bear), '50% is below the bear-market bar');
        $this->assertFalse(SignalEngine::acceptable(['win_low' => 0.30] + $odds, 2.0),
            'the pessimistic end of the interval is below break-even (1/3)');
        $this->assertFalse(SignalEngine::acceptable($odds, 1.2), 'reward/risk below 1.5');
    }

    /** The Python research lab writes config/weights.json keyed by factor name: both sides must spell them alike. */
    public function testCalibratedMultipliersMatchTheFactorNames(): void
    {
        preg_match_all("/new Factor\\('([^']+)'/u", (string) file_get_contents(dirname(__DIR__) . '/src/Analysis/Potential/PotentialAnalyzer.php'), $m);
        $php = array_values(array_unique($m[1]));
        sort($php);
        $python = dirname(__DIR__, 2) . '/python/tse_analyzer/potential.py';
        if (is_file($python)) {   // the deployed app ships without the Python lab
            preg_match('/FACTOR_NAMES = \\((.*?)\\)\n/su', (string) file_get_contents($python), $block);
            preg_match_all('/"([^"]+)"/u', $block[1] ?? '', $names);
            $py = $names[1];
            sort($py);
            $this->assertSame($php, $py, 'factor names differ between PotentialAnalyzer.php and potential.py');
        }
        $this->assertSame(13, count($php));

        $dir = sys_get_temp_dir() . '/sbg-weights-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/w.json", json_encode(['version' => 1, 'factors' => ['روند ثانویه' => 1.6, 'ارزش‌گذاری' => 1, 'x' => 9, 'y' => 'a']],
            JSON_UNESCAPED_UNICODE));
        $this->assertSame(['روند ثانویه' => 1.6, 'ارزش‌گذاری' => 1.0], \Sabadgardan\Analysis\Potential\PotentialAnalyzer::loadMultipliers("$dir/w.json"),
            'out-of-range and non-numeric entries are dropped');
        file_put_contents("$dir/bad.json", '{"version": 2, "factors": {"روند ثانویه": 2}}');
        $this->assertSame([], \Sabadgardan\Analysis\Potential\PotentialAnalyzer::loadMultipliers("$dir/bad.json"), 'unknown format');
        $this->assertSame([], \Sabadgardan\Analysis\Potential\PotentialAnalyzer::loadMultipliers("$dir/missing.json"));

        // A multiplier changes the factor's share of the score, nothing else.
        $app = $this->makeApp();
        $b = $app->market()->bundles([$app->market()->resolve('فملی')->insCode])[$app->market()->resolve('فملی')->insCode];
        $plain = (new \Sabadgardan\Analysis\Potential\PotentialAnalyzer())->assess($b->history, $b->info);
        $tilted = (new \Sabadgardan\Analysis\Potential\PotentialAnalyzer(0.35, 0.30, ['روند اولیه (Primary trend)' => 3.0]))->assess($b->history, $b->info);
        $byName = static fn ($r) => array_column(array_map(static fn ($f) => $f->toArray() + ['c' => $f->contribution], $r->factors), 'c', 'name');
        $p = $byName($plain);
        $t = $byName($tilted);
        $this->assertTrue(abs($t['روند اولیه (Primary trend)']) > abs($p['روند اولیه (Primary trend)']), 'its weight grows');
        $this->assertTrue(abs($plain->score - $tilted->score) > 0.01);
    }

    public function testFundPremiumShapesTheAdvice(): void
    {
        $advisor = new Advisor(25);
        $fund = new AssetClass(AssetClass::FUND_EQUITY);
        $plain = $advisor->advise(5, 80, 0.7, true, 5, 1, 'مجاز', 2.0, $fund);
        $this->assertSame('add', $plain['action']);

        $rich = $advisor->advise(5, 80, 0.7, true, 5, 1, 'مجاز', 2.0, $fund, 8.0);
        $this->assertSame('hold', $rich['action'], 'no new money above NAV');
        $this->assertContains('حباب 8.0٪', implode(' ', $rich['reasons']));

        $bubble = $advisor->advise(5, 80, 0.7, true, 5, 1, 'مجاز', 2.0, $fund, 15.0);
        $this->assertSame('trim', $bubble['action']);
        $this->assertTrue($bubble['target_weight'] < 5);

        $cheap = $advisor->advise(5, 80, 0.7, true, 5, 1, 'مجاز', 2.0, $fund, -4.0);
        $this->assertContains('زیر NAV', implode(' ', $cheap['reasons']));
        $stock = $advisor->advise(5, 80, 0.7, true, 5, 1, 'مجاز', 2.0, new AssetClass(AssetClass::STOCK), 15.0);
        $this->assertSame('add', $stock['action'], 'a premium means nothing for a share');
    }
}
