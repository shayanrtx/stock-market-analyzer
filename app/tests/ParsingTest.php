<?php

declare(strict_types=1);

namespace Tests;

use Sabadgardan\Analysis\MoneyFlow;
use Sabadgardan\Market\MarketData;

/** Response shapes differ between endpoints; parsers must accept every known variant. */
final class ParsingTest extends TestCase
{
    public function testClientTypeCountsInBothNamings(): void
    {
        $today = ['buy_I_Volume' => 1000, 'sell_I_Volume' => 1000, 'buy_CountI' => 10, 'sell_CountI' => 40];
        $history = ['recDate' => 20261019, 'buy_I_Volume' => 1000, 'sell_I_Volume' => 1000, 'buy_I_Count' => 10, 'sell_I_Count' => 40];
        $this->assertNear(4.0, MoneyFlow::fromRecord($today, 100)['buyer_power']);
        $this->assertNear(4.0, MoneyFlow::fromRecord($history, 100)['buyer_power'], 1e-9, 'history uses buy_I_Count');
        $daily = MoneyFlow::daily([$history]);
        $this->assertSame('2026-10-19', $daily[0]['date']);
        $this->assertNear(4.0, $daily[0]['buyer_power']);
    }

    public function testMarketWatchChangeFromPercentOrPrices(): void
    {
        $this->assertNear(2.5, MarketData::watchRow(['instrumentName' => 'فملی', 'closingPricePercent' => 2.5, 'tradeValue' => 9])['change']);
        $row = MarketData::watchRow(['instrumentName' => 'فملی', 'industryName' => 'فلزات اساسی',
            'closingPrice' => ['value' => 1050, 'state' => 0], 'yesterdayPrice' => 1000, 'tradeValue' => 5e9]);
        $this->assertNear(5.0, $row['change'], 1e-9, 'computed from the {value,state} cells of tse.ir');
        $this->assertSame('فلزات اساسی', $row['industry']);
        $this->assertNull(MarketData::watchRow(['instrumentName' => 'x']), 'no price, no row');
        $this->assertNull(MarketData::watchRow(['closingPrice' => 1, 'yesterdayPrice' => 1]));
    }
}
