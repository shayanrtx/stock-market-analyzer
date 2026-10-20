<?php

declare(strict_types=1);

namespace Tests;

use Sabadgardan\Market\AssetClass;
use Sabadgardan\Portfolio\Advisor;

final class AdvisorTest extends TestCase
{
    public function testReasonsQuoteTheNumbers(): void
    {
        // Regression: "$s؛" made PHP look for a variable named «s؛» and silently drop the score.
        $a = (new Advisor(25))->advise(10, 55, 0.35, true, 8, 1, 'مجاز');
        $this->assertContains('امتیاز پتانسیل رشد 55؛', $a['reasons'][0]);
        $this->assertContains('فقط 35٪', implode(' ', $a['reasons']));
        $this->assertSame('trim', $a['action']);
    }

    public function testHaltedSymbolCannotBeTraded(): void
    {
        $a = (new Advisor())->advise(10, 80, 0.8, true, 5, 1, 'ممنوع-متوقف');
        $this->assertSame('halted', $a['action']);
        $this->assertNear(10.0, $a['target_weight']);
    }

    public function testCashLikeHoldingsAreNotJudgedByScore(): void
    {
        $a = (new Advisor())->advise(30, 20, 0.2, false, 1, 1, 'مجاز', null, new AssetClass(AssetClass::FUND_FIXED_INCOME));
        $this->assertSame('hold', $a['action'], 'a fixed-income fund is the cash reserve, not a failing growth bet');
        $this->assertNear(30.0, $a['target_weight']);
        $this->assertContains('صندوق درآمد ثابت', $a['reasons'][0]);
    }

    public function testLeveragedFundsGetHalfTheWeightCap(): void
    {
        $a = (new Advisor(20))->advise(15, 70, 0.7, true, 15, 1, 'مجاز', 3.0, new AssetClass(AssetClass::FUND_LEVERAGED));
        $this->assertSame('trim', $a['action']);
        $this->assertNear(10.0, $a['target_weight']);
        $gold = (new Advisor())->advise(8, 60, 0.6, true, 3, 1, 'مجاز', 2.0, new AssetClass(AssetClass::FUND_GOLD));
        $this->assertContains('پوشش تورم', implode(' ', $gold['reasons']));
    }

    public function testRiskOverweightTriggersTrim(): void
    {
        $a = (new Advisor())->advise(10, 70, 0.7, true, 25, 1, 'مجاز', 2.0);
        $this->assertSame('trim', $a['action']);
        $this->assertTrue($a['target_weight'] < 10);
    }
}
