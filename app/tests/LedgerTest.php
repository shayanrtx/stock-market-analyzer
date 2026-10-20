<?php

declare(strict_types=1);

namespace Tests;

use Sabadgardan\Portfolio\FeeSchedule;
use Sabadgardan\Portfolio\Ledger;
use Sabadgardan\Portfolio\Transaction as T;

final class LedgerTest extends TestCase
{
    private function buy(?int $id, string $date, float $qty, float $price, float $fee = 0.0): T
    {
        return new T($id, T::BUY, $date, '1', 'فملی', $qty, $price, $fee);
    }

    private function sell(?int $id, string $date, float $qty, float $price, float $fee = 0.0): T
    {
        return new T($id, T::SELL, $date, '1', 'فملی', $qty, $price, $fee);
    }

    public function testWeightedAverageCostIncludesFees(): void
    {
        $l = new Ledger([$this->buy(1, '2025-01-01', 1000, 100, 50), $this->buy(2, '2025-02-01', 1000, 200, 100)]);
        $p = $l->openPositions()[0];
        $this->assertNear(2000, $p['quantity']);
        $this->assertNear((100_000 + 50 + 200_000 + 100) / 2000, $p['avg_cost']);
    }

    public function testRealizedPnlOnPartialSell(): void
    {
        $l = new Ledger([
            $this->buy(1, '2025-01-01', 1000, 100),
            $this->buy(2, '2025-01-02', 1000, 200),
            $this->sell(3, '2025-03-01', 500, 300, 120),
        ]);
        // avg cost 150: proceeds 150,000 − fee 120 − cost 75,000
        $this->assertNear(74_880, $l->totals()['realized']);
        $p = $l->openPositions()[0];
        $this->assertNear(1500, $p['quantity']);
        $this->assertNear(150, $p['avg_cost']);  // selling doesn't change the average cost
        $this->assertNear(225_000, $l->totals()['cost_basis']);
    }

    public function testBonusSharesLowerAverageCost(): void
    {
        $l = new Ledger([$this->buy(1, '2025-01-01', 1000, 100), new T(2, T::BONUS, '2025-05-01', '1', 'فملی', 1000)]);
        $p = $l->openPositions()[0];
        $this->assertNear(2000, $p['quantity']);
        $this->assertNear(50, $p['avg_cost']);
    }

    public function testCashAndDividends(): void
    {
        $l = new Ledger([
            new T(1, T::DEPOSIT, '2025-01-01', amount: 1_000_000),
            $this->buy(2, '2025-01-02', 1000, 500, 1856),
            new T(3, T::DIVIDEND, '2025-06-01', '1', 'فملی', amount: 40_000),
            $this->sell(4, '2025-07-01', 1000, 600, 5280),
            new T(5, T::WITHDRAW, '2025-07-02', amount: 100_000),
        ]);
        $t = $l->totals();
        $this->assertTrue($t['tracks_cash']);
        $this->assertNear(1_000_000 - 501_856 + 40_000 + 594_720 - 100_000, $t['cash']);
        $this->assertNear(40_000, $t['dividends']);
        $this->assertNear(594_720 - 501_856, $t['realized']);
        $this->assertSame([], $l->openPositions());
        $this->assertSame(1, count($l->closedPositions()));
    }

    public function testOversellIsRejectedOnSaveAndDelete(): void
    {
        $existing = [$this->buy(1, '2025-01-01', 1000, 100), $this->sell(2, '2025-02-01', 800, 120)];
        $this->expectException(\DomainException::class,
            fn () => Ledger::validateSave($existing, $this->sell(null, '2025-03-01', 300, 130)));
        // selling before the buy date is also an oversell
        $this->expectException(\DomainException::class,
            fn () => Ledger::validateSave([$existing[0]], $this->sell(null, '2024-12-01', 10, 130)));
        // deleting the buy would leave the sale uncovered
        $this->expectException(\DomainException::class, fn () => Ledger::validateDelete($existing, 1));
        // editing the sale down is fine
        Ledger::validateSave($existing, $this->sell(2, '2025-02-01', 500, 120));
    }

    public function testFeeSchedule(): void
    {
        $f = new FeeSchedule();
        $this->assertNear(3712, $f->fee('buy', 'سهام', 1000, 1000));
        $this->assertNear(8800, $f->fee('sell', 'سهام', 1000, 1000));
        $this->assertNear(1875, $f->fee('sell', 'صندوق', 1000, 1000));
    }
}
