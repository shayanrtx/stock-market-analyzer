<?php

declare(strict_types=1);

namespace Tests;

use Sabadgardan\Analysis\Sensitivity;
use Sabadgardan\Market\Disclosures;
use Sabadgardan\Market\FundNav;
use Sabadgardan\Market\TgjuClient;
use Sabadgardan\Market\TreasuryYield;

/** The demo market, except that one instrument's daily history stops $drop sessions early. */
final class StaleHistoryTransport implements \Sabadgardan\Market\Http\Transport
{
    public function __construct(private \Sabadgardan\Market\Http\Transport $inner, private string $code, private int $drop)
    {
    }

    public function fetchMany(array $urls, array $headers, int $timeout): array
    {
        $out = $this->inner->fetchMany($urls, $headers, $timeout);
        foreach ($out as $url => $res) {
            if (str_contains($url, "GetClosingPriceDailyList/{$this->code}/") && $res->status === 200) {
                $data = json_decode($res->body, true);
                $data['closingPriceDaily'] = array_slice($data['closingPriceDaily'], $this->drop);   // newest first
                $out[$url] = new \Sabadgardan\Market\Http\HttpResult(200, (string) json_encode($data));
            }
        }
        return $out;
    }
}

/** Treasury yields, announcements, fund NAVs, the dollar series and the rates built on them. */
final class MarketExtrasTest extends TestCase
{
    public function testTreasuryBillMaturityAndYield(): void
    {
        // YYMMDD after the last dash of the name; 1403/01/01 is 2024-03-20.
        $this->assertSame('2024-03-20', TreasuryYield::maturity('اخزا2001', 'اسناد خزانه-م1بودجه02-030101'));
        $this->assertSame('2024-03-20', TreasuryYield::maturity('اخزا۰۳۰۱۰۱', ''), 'old symbols carry the date, in Persian digits too');
        $this->assertNull(TreasuryYield::maturity('اخزا2001', 'اسناد خزانه-م1بودجه02-041231'), 'Esfand never has 31 days');
        $this->assertNull(TreasuryYield::maturity('اخزا2001', 'اسناد خزانه-م1بودجه02-031301'));
        $this->assertNull(TreasuryYield::maturity('فملی', 'ملی صنایع مس ایران'));

        $this->assertNear(0.25, TreasuryYield::ytm(800_000, 365), 1e-9, 'a year at 800k pays 25%');
        $this->assertNear(1.25 ** 0.5 - 1, TreasuryYield::ytm(800_000, 730), 1e-9, 'the same price two years out');
        $this->assertNull(TreasuryYield::ytm(1_000_500, 100), 'above face value is not a yield');
        $this->assertNull(TreasuryYield::ytm(900_000, 0));
    }

    public function testABillThatStoppedTradingIsLeftOut(): void
    {
        $app = $this->makeApp();
        $code = $app->market()->resolve('اخزا4024')->insCode;
        $app->withTransport(new StaleHistoryTransport(new \Sabadgardan\Market\Http\DemoTransport(fn (): int => $this->now), $code, 20));
        $t = TreasuryYield::fromMarket($app->uncachedMarket(), date('Y-m-d', $this->now));
        $this->assertSame(['اخزا5011', 'اخزا3014'], array_column($t['bills'], 'symbol'), 'its last trade is four weeks old');
    }

    public function testDisclosuresAreMergedAndClassified(): void
    {
        $items = Disclosures::merge(
            [['dEven' => 20261018, 'hEven' => 81500, 'tseTitle' => 'توقف نماد فملی', 'tseDesc' => 'جهت برگزاری مجمع'],
             ['dEven' => 20261019, 'hEven' => 90000, 'tseTitle' => 'بازگشایی نماد فملی', 'tseDesc' => '']],
            [['title' => 'گزارش فعالیت ماهانه دوره ۱ ماهه منتهی به ۱۴۰۵/۰۶/۳۱', 'publishDateTime' => '۱۴۰۵/۰۷/۲۸ ۱۸:۴۰:۱۲',
              'url' => '/Reports/Decision.aspx?LetterSerial=abc'],
             ['title' => 'تصمیمات مجمع عمومی عادی سالیانه', 'publishDateTime' => '2026-10-17T12:05:00', 'url' => ''],
             ['title' => '', 'publishDateTime' => '1405/07/01'],
             ['title' => 'لینک مشکوک', 'publishDateTime' => '1405/06/01', 'url' => 'javascript:alert(1)']]);
        $this->assertSame(5, count($items), 'untitled rows are dropped');
        $this->assertSame('', end($items)['url'], 'only http(s) links survive');
        $this->assertSame('2026-10-20 18:40', $items[0]['at'], 'Jalali Codal time, newest first');
        $this->assertSame('monthly', $items[0]['kind']);
        $this->assertSame('https://www.codal.ir/Reports/Decision.aspx?LetterSerial=abc', $items[0]['url']);
        $this->assertFalse($items[0]['important']);
        $this->assertSame(['halt', 'halt', 'agm', 'other'], array_column(array_slice($items, 1), 'kind'));
        $this->assertTrue($items[1]['important']);
        $this->assertSame('ناظر', $items[1]['source']);
        $this->assertSame('dividend', Disclosures::kind('زمان‌بندی پرداخت سود نقدی'));
        $this->assertSame('capital', Disclosures::kind('افزایش سرمایه از محل سود انباشته'));
    }

    public function testFundNavPremiumAndNameMatching(): void
    {
        $nav = FundNav::fromRecord(['pRedTran' => 10000, 'pSubTran' => 10050]);
        $this->assertNear(10000.0, $nav['redemption']);
        $this->assertNear(8.0, FundNav::premium(10800, $nav), 1e-9);
        $this->assertNull(FundNav::premium(null, $nav));
        $this->assertNull(FundNav::fromRecord(['navRed' => 0]));

        $this->assertTrue(FundNav::sameFund('صندوق س. پشتوانه طلای مفید', 'صندوق سرمایه گذاری پشتوانه طلای مفید'));
        $this->assertFalse(FundNav::sameFund('صندوق س. پشتوانه طلای مفید', 'صندوق سرمایه گذاری پشتوانه طلای لوتوس'));
    }

    public function testTgjuRowsAreParsed(): void
    {
        $series = TgjuClient::parse([
            ['1,030,000', '1,025,000', '1,041,000', '<span class="high">1,035,500</span>', '5,500', '0.53%', '2026/10/18', '۱۴۰۵/۰۷/۲۶'],
            ['1,020,000', '1,018,000', '1,031,000', '1,030,000', '-', '-', '2026/10/17', '۱۴۰۵/۰۷/۲۵'],
            ['bad row'],
        ]);
        $this->assertSame(['2026-10-17', '2026-10-18'], array_keys($series), 'oldest first');
        $this->assertNear(1_035_500.0, $series['2026-10-18']);
    }

    public function testRiskFreeRateFollowsTreasuryBillsUnlessTurnedOff(): void
    {
        $app = $this->makeApp();
        $uid = $app->auth()->createUser('saver', 'password1', 'پس‌انداز', false);
        $t = $app->rates()->treasury();
        $this->assertSame(3, count($t['bills']), 'the demo market lists three bills two months to two years out');
        $this->assertTrue($t['rate'] > 0.2 && $t['rate'] < 0.4, 'a plausible Iranian bill yield');
        $this->assertNear($t['rate'], $app->rates()->riskFree($uid));
        $this->assertSame('بازده اسناد خزانه', $app->rates()->source($uid));

        $app->settings()->set($uid, 'risk_free_auto', '0');
        $app->settings()->set($uid, 'risk_free_annual', '0.27');
        $this->assertNear(0.27, $app->rates()->riskFree($uid));
        $this->assertSame('تنظیمات', $app->rates()->source($uid));
    }

    public function testDollarSensitivity(): void
    {
        // A symbol whose every move is twice the dollar's (in log terms): beta ≈ 2, correlation ≈ 1.
        $dates = $close = $driver = [];
        $usd = 500_000.0;
        for ($d = 0; $d < 420; $d++) {
            $date = date('Y-m-d', strtotime('2025-08-01 UTC') + $d * 86400);
            $usd *= 1 + 0.01 * sin($d * 1.7) + 0.004 * cos($d * 0.31);
            $driver[$date] = $usd;
            $dates[] = $date;
            $close[] = 1000 * ($usd / 500_000) ** 2;
        }
        $s = Sensitivity::versus($dates, $close, $driver);
        $this->assertTrue($s['weeks'] >= 50);
        $this->assertNear(2.0, $s['beta'], 0.05);
        $this->assertNear(1.0, $s['correlation'], 0.01);
        $this->assertContains('هم‌جهت', Sensitivity::describe($s));
        $this->assertNull(Sensitivity::versus(array_slice($dates, 0, 30), array_slice($close, 0, 30), $driver), 'too short');
    }
}
