<?php

declare(strict_types=1);

namespace Tests;

use Sabadgardan\Market\PriceHistory;
use Sabadgardan\Signals\SignalTracker as T;

final class SignalTrackerTest extends TestCase
{
    /** @param list<array{0: float, 1: float, 2: float, 3?: float}> $bars high, low, close[, yesterday] from 2026-01-03 */
    private function history(array $bars): PriceHistory
    {
        $h = new PriceHistory();
        $prev = 1000.0;
        foreach ($bars as $i => $b) {
            $h->dates[] = date('Y-m-d', strtotime('2026-01-03') + $i * 86400);
            $h->open[] = $prev;
            $h->high[] = $b[0];
            $h->low[] = $b[1];
            $h->close[] = $b[2];
            $h->yesterday[] = $b[3] ?? $prev;
            $h->volume[] = 1000;
            $h->value[] = 1e6;
            $prev = $b[2];
        }
        return $h;
    }

    private function signal(string $side = 'buy'): array
    {
        return ['day' => '20260103', 'side' => $side, 'entry' => 1000, 'stop' => 950, 'target' => 1100];
    }

    public function testTargetReachedFirst(): void
    {
        $h = $this->history([[1000, 1000, 1000], [1040, 990, 1030], [1105, 1020, 1090]]);
        $o = T::outcome($this->signal(), $h);
        $this->assertSame(T::TARGET, $o['status']);
        $this->assertNear(10.0, $o['return_pct']);
        $this->assertSame(2, $o['days']);
    }

    public function testDayTouchingBothCountsAsStop(): void
    {
        $h = $this->history([[1000, 1000, 1000], [1110, 940, 1000]]);
        $this->assertSame(T::STOP, T::outcome($this->signal(), $h)['status']);
    }

    public function testSignalDaySessionIsIgnored(): void
    {
        // The signal was issued during the first session: its own high/low may predate it.
        $h = $this->history([[1200, 900, 1000]]);
        $this->assertSame(T::NEW, T::outcome($this->signal(), $h)['status']);
    }

    public function testCapitalIncreaseIsNotMistakenForAStop(): void
    {
        // 100% bonus shares halve the price on day 3: the reference price drops to 500.
        $h = $this->history([[1000, 1000, 1000], [1020, 990, 1010], [515, 495, 510, 505]]);
        $o = T::outcome($this->signal(), $h);
        $this->assertSame(T::OPEN, $o['status']);
        $this->assertNear(2.0, $o['return_pct'], 0.01);
    }

    public function testExpiredAfterHorizon(): void
    {
        $bars = [[1000, 1000, 1000]];
        for ($i = 0; $i < 5; $i++) {
            $bars[] = [1010, 990, 1005];
        }
        $o = T::outcome($this->signal(), $this->history($bars), 3);
        $this->assertSame(T::EXPIRED, $o['status']);
        $this->assertSame(3, $o['days']);
    }

    public function testSummary(): void
    {
        $rows = [
            ['side' => 'buy', 'outcome' => ['status' => T::TARGET, 'return_pct' => 10.0]],
            ['side' => 'buy', 'outcome' => ['status' => T::STOP, 'return_pct' => -5.0]],
            ['side' => 'buy', 'outcome' => ['status' => T::OPEN, 'return_pct' => 3.0]],
            ['side' => 'sell', 'outcome' => ['status' => T::OPEN, 'return_pct' => -4.0]],
        ];
        $s = T::summary($rows);
        $this->assertNear(0.5, $s['win_rate']);
        $this->assertNear(2.5, $s['avg_buy_return_pct']);
        $this->assertNear(-4.0, $s['avg_sell_move_pct']);
        $this->assertNear(3.0, $s['buys']);
    }
}
