<?php

declare(strict_types=1);

namespace Tests;

use Sabadgardan\Live\MarketClock;
use Sabadgardan\Market\Http\DemoTransport;
use Sabadgardan\Market\PriceHistory;

/** The synthetic market must behave like the real one, or demo mode would mislead. */
final class DemoTransportTest extends TestCase
{
    private const BASE = 'https://cdn.tsetmc.com/api/';

    private function get(DemoTransport $t, string $path): array
    {
        $res = $t->fetchMany([self::BASE . $path], [], 5)[self::BASE . $path];
        $this->assertSame(200, $res->status, $path);
        return json_decode($res->body, true);
    }

    private function code(DemoTransport $t, string $symbol): string
    {
        return $this->get($t, 'Instrument/GetInstrumentSearch/' . rawurlencode($symbol))['instrumentSearch'][0]['insCode'];
    }

    public function testDailyMovesStayWithinThePriceLimit(): void
    {
        $t = new DemoTransport(static fn () => strtotime('2026-10-20 14:00 UTC'));
        foreach (['فملی', 'خودرو', 'وبملت'] as $symbol) {
            $rows = $this->get($t, 'ClosingPrice/GetClosingPriceDailyList/' . $this->code($t, $symbol) . '/0')['closingPriceDaily'];
            $this->assertTrue(count($rows) > 700, 'about four years of sessions');
            foreach ($rows as $r) {
                $move = $r['pClosing'] / $r['priceYesterday'] - 1;
                $this->assertTrue(abs($move) <= 0.0501, "$symbol {$r['dEven']} moved " . round($move * 100, 2) . '%');
                $this->assertTrue($r['priceMax'] >= $r['pClosing'] && $r['priceMin'] <= $r['pClosing']);
            }
        }
    }

    public function testDeterministicAndFollowsTheClock(): void
    {
        $morning = new DemoTransport(static fn () => strtotime('2026-10-20 10:00 UTC') - MarketClock::OFFSET);
        $evening = new DemoTransport(static fn () => strtotime('2026-10-20 16:00 UTC') - MarketClock::OFFSET);
        $code = $this->code($morning, 'فملی');
        $path = "ClosingPrice/GetClosingPriceDailyList/$code/2";
        $this->assertSame($this->get($morning, $path), $this->get(new DemoTransport(static fn () => strtotime('2026-10-20 10:00 UTC') - MarketClock::OFFSET), $path));
        $this->assertSame(20261019, $this->get($morning, $path)['closingPriceDaily'][0]['dEven'], 'today is not final during trading');
        $this->assertSame(20261020, $this->get($evening, $path)['closingPriceDaily'][0]['dEven']);
        $live = $this->get($morning, "ClosingPrice/GetClosingPriceInfo/$code")['closingPriceInfo'];
        $this->assertTrue($live['qTotTran5J'] > 0 && $live['qTotTran5J'] < $this->get($evening, "ClosingPrice/GetClosingPriceInfo/$code")['closingPriceInfo']['qTotTran5J']);
    }

    public function testCapitalIncreasesAreAdjustedAway(): void
    {
        $t = new DemoTransport(static fn () => strtotime('2026-10-20 14:00 UTC'));
        $rows = $this->get($t, 'ClosingPrice/GetClosingPriceDailyList/' . $this->code($t, 'رمپنا') . '/0')['closingPriceDaily'];
        $h = PriceHistory::fromClosingPriceDaily($rows)->adjusted();
        $big = array_filter($h->adjustments, static fn ($a) => $a[1] < 0.9);
        $this->assertSame(1, count($big), 'one capital increase');
        $maxDrop = 0.0;
        for ($i = 1; $i < $h->count(); $i++) {
            $maxDrop = min($maxDrop, $h->close[$i] / $h->close[$i - 1] - 1);
        }
        $this->assertTrue($maxDrop > -0.06, 'no artificial crash in the adjusted series');
    }
}
