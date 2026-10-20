<?php

declare(strict_types=1);

namespace Tests;

use Sabadgardan\Analysis\Indicators;
use Sabadgardan\Analysis\Stats;

/** Golden values come from the pandas implementation (tests/fixtures/indicators_golden.json). */
final class IndicatorsTest extends TestCase
{
    private array $g;

    public function setUp(): void
    {
        $this->g = json_decode((string) file_get_contents(__DIR__ . '/fixtures/indicators_golden.json'), true);
    }

    private function assertSeries(array $expected, array $actual, string $name): void
    {
        $this->assertSame(count($expected), count($actual), "$name length");
        foreach ($expected as $i => $e) {
            if ($e === null) {
                $this->assertNull($actual[$i], "{$name}[{$i}] should be null");
            } else {
                $this->assertNear($e, $actual[$i], 1e-9, "{$name}[{$i}]");
            }
        }
    }

    public function testMatchesPandas(): void
    {
        $c = $this->g['close'];
        $this->assertSeries($this->g['sma20'], Indicators::sma($c, 20), 'sma20');
        $this->assertSeries($this->g['ema12'], Indicators::ema($c, 12), 'ema12');
        $this->assertSeries($this->g['rsi14'], Indicators::rsi($c, 14), 'rsi14');
        $m = Indicators::macd($c);
        $this->assertSeries($this->g['macd'], $m['macd'], 'macd');
        $this->assertSeries($this->g['signal'], $m['signal'], 'signal');
        $this->assertSeries($this->g['hist'], $m['hist'], 'hist');
        $bb = Indicators::bollinger($c);
        $this->assertSeries($this->g['bb_upper'], $bb['upper'], 'bb_upper');
        $this->assertSeries($this->g['bb_lower'], $bb['lower'], 'bb_lower');
        $this->assertSeries($this->g['atr14'], Indicators::atr($this->g['high'], $this->g['low'], $c), 'atr14');
    }

    public function testRsiBounds(): void
    {
        $up = range(1, 40);
        $this->assertNear(100.0, Stats::last(Indicators::rsi(array_map('floatval', $up))));
        $down = range(40, 1, -1);
        $this->assertNear(0.0, Stats::last(Indicators::rsi(array_map('floatval', $down))), 1e-9);
    }

    public function testStats(): void
    {
        $this->assertNear(2.5, Stats::quantile([1, 2, 3, 4], 0.5));
        $this->assertNear(1.75, Stats::quantile([1, 2, 3, 4], 0.25));
        $this->assertNear(sqrt(5 / 3), Stats::std([1, 2, 3, 4]));
        $this->assertNear(21.0, Stats::pctReturn([100, 110, 121], 2));
        $this->assertNull(Stats::pctReturn([100, 110], 5));
        $this->assertNear(1.0, Stats::correlation([1, 2, 3], [2, 4, 6]));
    }
}
