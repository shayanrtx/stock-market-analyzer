<?php

declare(strict_types=1);

namespace Tests;

use Sabadgardan\Image\PersianShaper;
use Sabadgardan\Live\MarketClock;
use Sabadgardan\Market\Jalali;
use Sabadgardan\Market\Text;

final class CalendarAndTextTest extends TestCase
{
    public function testJalaliKnownDates(): void
    {
        $this->assertSame([1403, 1, 1], Jalali::fromGregorian(2024, 3, 20));   // Nowruz 1403
        $this->assertSame([1405, 7, 28], Jalali::fromGregorian(2026, 10, 20));
        $this->assertSame([2025, 3, 21], Jalali::toGregorian(1404, 1, 1));
        $this->assertSame('1403/12/30', Jalali::fromGregorianString('2025-03-20'));  // leap year end
    }

    public function testJalaliRoundTripForEveryDayOfFiveYears(): void
    {
        for ($t = strtotime('2022-01-01 12:00 UTC'); $t < strtotime('2027-01-01 UTC'); $t += 86400) {
            [$y, $m, $d] = array_map('intval', explode('-', gmdate('Y-m-d', $t)));
            [$jy, $jm, $jd] = Jalali::fromGregorian($y, $m, $d);
            $this->assertSame([$y, $m, $d], Jalali::toGregorian($jy, $jm, $jd), gmdate('Y-m-d', $t));
        }
    }

    public function testParseAcceptsPersianDigitsAndRejectsInvalidDates(): void
    {
        $this->assertSame('2026-10-20', Jalali::parse('۱۴۰۵/۰۷/۲۸'));
        $this->assertSame('2026-10-20', Jalali::parse('1405-7-28'));
        $this->assertNull(Jalali::parse('1405/13/01'));
        $this->assertNull(Jalali::parse('1404/12/31'));  // 1404 is not a leap year
        $this->assertNull(Jalali::parse('فردا'));
    }

    public function testTextNormalisation(): void
    {
        $this->assertSame('وبملت', Text::normalizeSymbol('وبملت '));
        $this->assertSame(Text::normalizeSymbol('كگل'), Text::normalizeSymbol('کگل'));
        $this->assertSame(1234567.0, Text::parseNumber('۱,۲۳۴,۵۶۷'));
        $this->assertNull(Text::parseNumber(''));
        $this->assertSame(['72.00', 'میلیارد تومان'], Text::moneyParts(720_000_000_000));
        $this->assertSame(['1.5', 'هزار میلیارد تومان'], Text::moneyParts(15_000_000_000_000));
    }

    public function testMarketClockPhases(): void
    {
        $at = static fn (string $tehran) => strtotime("$tehran UTC") - MarketClock::OFFSET;
        $this->assertSame(MarketClock::PRE_OPEN, MarketClock::phase($at('2026-10-20 08:50')));
        $this->assertSame(MarketClock::CONTINUOUS, MarketClock::phase($at('2026-10-20 11:00')));
        $this->assertSame(MarketClock::CLOSED, MarketClock::phase($at('2026-10-20 14:00')));
        $this->assertSame(MarketClock::CLOSED, MarketClock::phase($at('2026-10-22 11:00')));  // Thursday
        $this->assertSame(MarketClock::CLOSED, MarketClock::phase($at('2026-10-20 11:00'), false));  // holiday
    }

    public function testShaperKeepsDigitsInReadingOrder(): void
    {
        $visual = PersianShaper::visual('قیمت 1234');
        $this->assertContains('1234', $visual, 'digits must not be reversed');
    }
}
