<?php

declare(strict_types=1);

namespace Tests;

use Sabadgardan\Analysis\Potential\HistoricalStudy;
use Sabadgardan\Analysis\Potential\PotentialAnalyzer;
use Sabadgardan\Market\PriceHistory;

/** Parity with the tested Python engine (tests/fixtures/potential_golden.json) plus PHP-only features. */
final class PotentialTest extends TestCase
{
    private array $g;
    private PriceHistory $h;

    public function setUp(): void
    {
        $this->g = json_decode((string) file_get_contents(__DIR__ . '/fixtures/potential_golden.json'), true);
        $g = $this->g;
        $this->h = new PriceHistory($g['dates'], $g['yesterday'], $g['high'], $g['low'], $g['close'], $g['yesterday'],
            $g['volume'], $g['value']);
    }

    public function testMatchesPythonEngine(): void
    {
        $r = (new PotentialAnalyzer())->assess($this->h, $this->g['info'], [], $this->g['flow_records']);
        $e = $this->g['expected'];
        $this->assertNear($e['score'], $r->score, 1e-6, 'score');
        foreach ($e['factors'] as $name => $contribution) {
            $match = array_values(array_filter($r->factors, static fn ($f) => $f->name === $name));
            $this->assertTrue(count($match) === 1, "factor $name missing");
            $this->assertNear($contribution, $match[0]->contribution, 1e-6, $name);
        }
        $this->assertSame(count($e['factors']), count($r->factors), 'factor count');
        foreach (['target', 'support', 'stop', 'reward_risk', 'atr'] as $k) {
            if ($e['levels'][$k] === null) {
                $this->assertNull($r->levels[$k], $k);
            } else {
                $this->assertNear($e['levels'][$k], $r->levels[$k], 1e-6, $k);
            }
        }
        foreach ($e['scenarios'] as $k => $v) {
            $this->assertNear($v, $r->scenarios[$k], 1e-6, "scenario $k");
        }
        $this->assertNear($e['probability_up'], $r->probabilityUp, 1e-9, 'probability_up');
        $this->assertNear($e['cagr_pct'], $r->history['cagr_pct'], 1e-6, 'cagr');
        $this->assertSame(array_map('intval', array_keys($e['annual_returns'])), array_keys($r->history['annual_returns']));
        $this->assertSame($e['analog_samples'], $r->analogs['samples']);
        $this->assertContains('ارزیابی کلی', $r->summary);
    }

    public function testAdjustmentRemovesCapitalIncreaseGap(): void
    {
        $g = $this->g;
        $close = $g['close'];
        $yest = $g['yesterday'];
        // Capital increase on day 500: from then on prices are halved; reference price on that day too.
        for ($i = 500; $i < count($close); $i++) {
            $close[$i] /= 2;
            $yest[$i] /= 2;
        }
        $h = new PriceHistory($g['dates'], $yest, $close, $close, $close, $yest, $g['volume'], $g['value']);
        $adj = $h->adjusted();
        $this->assertSame(1, count($adj->adjustments));
        $this->assertNear(0.5, $adj->adjustments[0][1], 0.02);
        $this->assertNear($close[699], $adj->close[699]);
        $worst = min(array_map(static fn ($a, $b) => $b / $a - 1, array_slice($adj->close, 0, -1), array_slice($adj->close, 1)));
        $this->assertTrue($worst > -0.1, 'no artificial crash after adjustment');
    }

    public function testTradeOddsAreConsistent(): void
    {
        $study = new HistoricalStudy($this->h);
        $odds = $study->tradeOdds(10, 5);
        $this->assertTrue($odds !== null && $odds['samples'] >= 6);
        $this->assertNear(1.0, $odds['win_rate'] + $odds['loss_rate'] + $odds['timeout_rate'], 1e-9);
        $wide = $study->tradeOdds(10, 30);   // a far stop is hit less often
        $tight = $study->tradeOdds(10, 2);
        $this->assertTrue($wide['loss_rate'] <= $tight['loss_rate']);
    }

    public function testShortHistoryDegradesGracefully(): void
    {
        $short = $this->h->tail(40);
        $r = (new PotentialAnalyzer())->assess($short);
        $this->assertSame('پایین', $r->confidence);
        $this->assertNull($r->analogs);
        $this->assertTrue($r->score >= 0 && $r->score <= 100);
    }
}
