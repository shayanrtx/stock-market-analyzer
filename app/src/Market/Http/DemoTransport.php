<?php

declare(strict_types=1);

namespace Sabadgardan\Market\Http;

use Sabadgardan\Live\MarketClock;
use Sabadgardan\Market\Jalali;
use Sabadgardan\Market\Text;

/**
 * Synthetic market for demo mode (``market.transport = demo``): answers the same endpoints as
 * cdn.tsetmc.com and webgw.tse.ir with deterministic, internally consistent data, so the whole
 * application can be tried (and screenshotted) outside Iran. Nothing here is real market data;
 * the layout shows a "demo data" banner whenever this transport is active.
 *
 * Model: a market factor with slow regimes drives every symbol through its beta, plus
 * idiosyncratic noise and drift; daily moves are clipped to the ±5% price limit; some symbols
 * have capital increases and cash dividends (so price adjustment is exercised); each day one
 * symbol closes limit-up and one limit-down (queues for the live monitor). Today's prices follow
 * the Tehran clock: pre-open orders from 8:45, continuous trading 9:00-12:30, final row after.
 */
final class DemoTransport implements Transport
{
    private const START = '2022-09-24';          // first generated session (a Saturday)
    private const TEDPIX = '32097828799138957';
    private const LIMIT = 0.05;

    /**
     * symbol, name, sector, ISIN, market, end-price scale, beta, daily idio vol, annual drift,
     * EPS as a share of price, sector P/E, shares outstanding (millions); optional events:
     * capital increase [day index => bonus ratio], dividend month (Jalali) and DPS share, TSE sub-sector.
     */
    private const UNIVERSE = [
        ['فملی', 'ملی‌ صنایع‌ مس‌ ایران‌', 'فلزات اساسی', 'IRO1MSMI0001', 'بورس', 7400, 1.15, 0.017, 0.38, 0.11, 7.5, 600000, [520 => 0.5], 5, 0.06],
        ['فولاد', 'فولاد مبارکه‌ اصفهان‌', 'فلزات اساسی', 'IRO1FOLD0001', 'بورس', 5650, 1.05, 0.016, 0.30, 0.13, 7.5, 800000, [], 4, 0.08],
        ['شپنا', 'پالایش‌ نفت‌ اصفهان‌', 'فراورده‌های نفتی، کک و سوخت هسته‌ای', 'IRO1PNES0001', 'بورس', 7150, 1.2, 0.018, 0.33, 0.12, 6.8, 300000, [610 => 0.3], 4, 0.05],
        ['شستا', 'سرمایه‌ گذاری‌ تامین اجتماعی', 'شرکت‌های چند رشته‌ای صنعتی', 'IRO1TAMN0001', 'بورس', 1080, 1.3, 0.019, 0.25, 0.10, 6.0, 1500000, [], 3, 0.07],
        ['خودرو', 'ایران‌ خودرو', 'خودرو و ساخت قطعات', 'IRO1IKCO0001', 'بورس', 2650, 1.45, 0.024, 0.20, -0.02, 9.0, 300000, [], 0, 0.0],
        ['خساپا', 'سایپا', 'خودرو و ساخت قطعات', 'IRO1SIPA0001', 'بورس', 2120, 1.5, 0.025, 0.12, -0.05, 9.0, 250000, [], 0, 0.0],
        ['وبملت', 'بانک ملت', 'بانک‌ها و موسسات اعتباری', 'IRO1BMLT0001', 'بورس', 2930, 1.1, 0.019, 0.36, 0.09, 6.5, 400000, [], 4, 0.03],
        ['وتجارت', 'بانک تجارت', 'بانک‌ها و موسسات اعتباری', 'IRO1BTEJ0001', 'بورس', 1720, 1.15, 0.020, 0.28, 0.07, 6.5, 300000, [], 0, 0.0],
        ['فارس', 'صنایع پتروشیمی خلیج فارس', 'محصولات شیمیایی', 'IRO1PFAJ0001', 'بورس', 11300, 0.95, 0.015, 0.34, 0.12, 7.2, 220000, [], 3, 0.09],
        ['پارسان', 'گسترش نفت و گاز پارسیان', 'محصولات شیمیایی', 'IRO1PRSN0001', 'بورس', 16100, 0.85, 0.014, 0.30, 0.13, 7.2, 60000, [], 3, 0.10],
        ['اخابر', 'مخابرات ایران', 'مخابرات', 'IRO1MKBT0001', 'بورس', 3880, 0.8, 0.016, 0.42, 0.09, 8.0, 140000, [], 3, 0.05],
        ['کگل', 'معدنی و صنعتی گل گهر', 'استخراج کانه‌های فلزی', 'IRO1GGOL0001', 'بورس', 6050, 1.0, 0.016, 0.31, 0.15, 6.5, 120000, [460 => 0.4], 5, 0.08],
        ['کچاد', 'معدنی و صنعتی چادرملو', 'استخراج کانه‌های فلزی', 'IRO1CHML0001', 'بورس', 9100, 1.0, 0.017, 0.27, 0.14, 6.5, 60000, [], 5, 0.09],
        ['شبندر', 'پالایش نفت بندرعباس', 'فراورده‌های نفتی، کک و سوخت هسته‌ای', 'IRO1PNBA0001', 'بورس', 12400, 1.15, 0.018, 0.40, 0.11, 6.8, 120000, [], 4, 0.05],
        ['تاپیکو', 'سرمایه‌گذاری نفت و گاز و پتروشیمی تامین', 'شرکت‌های چند رشته‌ای صنعتی', 'IRO1TAPI0001', 'بورس', 9300, 1.1, 0.017, 0.33, 0.10, 6.0, 90000, [], 3, 0.06],
        ['رمپنا', 'گروه مپنا', 'خدمات فنی و مهندسی', 'IRO1MAPN0001', 'بورس', 4500, 1.2, 0.020, 0.47, 0.08, 9.5, 90000, [700 => 0.6], 0, 0.0],
        ['وغدیر', 'سرمایه‌گذاری غدیر', 'سرمایه‌گذاری‌ها', 'IRO1GDIR0001', 'بورس', 2840, 1.05, 0.018, 0.29, 0.10, 6.2, 150000, [], 3, 0.06],
        ['شتران', 'پالایش نفت تهران', 'فراورده‌های نفتی، کک و سوخت هسته‌ای', 'IRO1PTEH0001', 'بورس', 4020, 1.25, 0.021, 0.18, 0.09, 6.8, 150000, [], 4, 0.04],
        ['حکشتی', 'کشتیرانی جمهوری اسلامی ایران', 'حمل و نقل آبی', 'IRO1KSHJ0001', 'بورس', 3420, 1.3, 0.022, 0.15, 0.09, 7.0, 240000, [], 0, 0.0],
        ['ذوب', 'ذوب آهن اصفهان', 'فلزات اساسی', 'IRO3ZOBZ0001', 'فرابورس', 1980, 1.35, 0.023, 0.10, -0.01, 7.5, 900000, [], 0, 0.0],
        ['مارون', 'پتروشیمی مارون', 'محصولات شیمیایی', 'IRO3PMRZ0001', 'فرابورس', 98500, 0.7, 0.013, 0.28, 0.14, 7.2, 3000, [], 3, 0.12],
        ['کمند', 'صندوق سرمایه‌گذاری درآمد ثابت کمند', 'صندوق سرمایه گذاری قابل معامله', 'IRT3KMDF0001', 'فرابورس', 24600, 0.02, 0.0012, 0.29, 0.0, 0.0, 500, [], 0, 0.0, 'صندوق سرمايه گذاري با درآمد ثابت'],
        ['اهرم', 'صندوق اهرمی کاریزما', 'صندوق سرمایه گذاری قابل معامله', 'IRT1AHRM0001', 'بورس', 15700, 2.0, 0.010, 0.30, 0.0, 0.0, 800, [], 0, 0.0, 'صندوق هاي سرمايه گذاري اهرمي'],
        ['عیار', 'صندوق س. پشتوانه طلای مفید', 'صندوق سرمایه گذاری قابل معامله', 'IRT1AYAR0001', 'بورس', 41800, 0.05, 0.013, 0.38, 0.0, 0.0, 2000, [], 0, 0.0, 'صندوق سرمايه گذاري كالايي'],
        // Treasury bills: the end price is set from the target yield (index 8) and the maturity in the name.
        ['اخزا5011', 'اسناد خزانه-م5بودجه03-051123', 'اوراق مشارکت و صکوک', 'IRB3TR051123', 'فرابورس', 0, 0.0, 0.0006, 0.288, 0.0, 0.0, 10, [], 0, 0.0, 'اسناد خزانه اسلامي'],
        ['اخزا3014', 'اسناد خزانه-م1بودجه03-060118', 'اوراق مشارکت و صکوک', 'IRB3TR060118', 'فرابورس', 0, 0.0, 0.0006, 0.297, 0.0, 0.0, 10, [], 0, 0.0, 'اسناد خزانه اسلامي'],
        ['اخزا4024', 'اسناد خزانه-م2بودجه04-070614', 'اوراق مشارکت و صکوک', 'IRB3TR070614', 'فرابورس', 0, 0.0, 0.0006, 0.305, 0.0, 0.0, 10, [], 0, 0.0, 'اسناد خزانه اسلامي'],
    ];

    /** Extra names that appear only in the market watch (breadth, sector map, movers). */
    private const WATCH_EXTRA = [
        ['وبصادر', 'بانک‌ها و موسسات اعتباری'], ['ونوین', 'بانک‌ها و موسسات اعتباری'], ['وپاسار', 'بانک‌ها و موسسات اعتباری'],
        ['فخوز', 'فلزات اساسی'], ['فسپا', 'فلزات اساسی'], ['فاسمین', 'فلزات اساسی'], ['کاوه', 'فلزات اساسی'],
        ['شپدیس', 'محصولات شیمیایی'], ['جم', 'محصولات شیمیایی'], ['شخارک', 'محصولات شیمیایی'], ['زاگرس', 'محصولات شیمیایی'],
        ['خگستر', 'خودرو و ساخت قطعات'], ['خپارس', 'خودرو و ساخت قطعات'], ['خزامیا', 'خودرو و ساخت قطعات'],
        ['همراه', 'مخابرات'], ['ثبهساز', 'انبوه‌سازی، املاک و مستغلات'], ['وساخت', 'انبوه‌سازی، املاک و مستغلات'],
        ['کرمان', 'استخراج کانه‌های فلزی'], ['کنور', 'استخراج کانه‌های فلزی'], ['غکورش', 'محصولات غذایی'],
        ['غبشهر', 'محصولات غذایی'], ['دی', 'بانک‌ها و موسسات اعتباری'], ['دانا', 'مواد و محصولات دارویی'],
        ['دسبحا', 'مواد و محصولات دارویی'], ['دفارا', 'مواد و محصولات دارویی'], ['سفارس', 'سیمان، آهک و گچ'],
        ['ستران', 'سیمان، آهک و گچ'], ['سخوز', 'سیمان، آهک و گچ'], ['بترانس', 'ساخت دستگاه‌ها و وسایل برقی'],
        ['بکاب', 'ساخت دستگاه‌ها و وسایل برقی'], ['ومعادن', 'سرمایه‌گذاری‌ها'], ['وصندوق', 'سرمایه‌گذاری‌ها'],
    ];

    /** @var array<string, mixed> per-process memo */
    private static array $memo = [];

    /** @var null|\Closure(): int */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ? \Closure::fromCallable($clock) : null;
    }

    public function fetchMany(array $urls, array $headers, int $timeout): array
    {
        $out = [];
        foreach ($urls as $url) {
            $body = $this->route((string) parse_url($url, PHP_URL_PATH));
            $out[$url] = $body === null
                ? new HttpResult(404, '{"error":"not found"}')
                : new HttpResult(200, (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        }
        return $out;
    }

    private function now(): int
    {
        return $this->clock ? ($this->clock)() : time();
    }

    // ---------------------------------------------------------------- routing

    private function route(string $path): mixed
    {
        $path = rawurldecode($path);
        $p = explode('/', trim(preg_replace('#^/api/#', '', $path) ?? '', '/'));
        $code = $p[2] ?? '';
        return match (true) {
            $p[0] === 'Instrument' && ($p[1] ?? '') === 'GetInstrumentSearch' => ['instrumentSearch' => $this->search($p[2] ?? '')],
            $p[0] === 'Instrument' && ($p[1] ?? '') === 'GetInstrumentInfo' => $this->known($code) ? ['instrumentInfo' => $this->info($code)] : null,
            $p[0] === 'ClosingPrice' && ($p[1] ?? '') === 'GetClosingPriceDailyList' => $this->known($code)
                ? ['closingPriceDaily' => $this->dailyList($code, (int) ($p[3] ?? 0))] : null,
            $p[0] === 'ClosingPrice' && ($p[1] ?? '') === 'GetClosingPriceInfo' => $this->known($code) ? ['closingPriceInfo' => $this->priceInfo($code)] : null,
            $p[0] === 'ClientType' && ($p[1] ?? '') === 'GetClientType' => $this->known($code) ? ['clientType' => $this->clientToday($code)] : null,
            $p[0] === 'ClientType' && ($p[1] ?? '') === 'GetClientTypeHistory' => $this->known($code) ? ['clientType' => $this->clientHistory($code)] : null,
            $p[0] === 'ClientType' && ($p[1] ?? '') === 'GetClientTypeAll' => ['clientTypeAllDto' => []],
            $p[0] === 'BestLimits' => $this->known($p[1] ?? '') ? ['bestLimits' => $this->bestLimits($p[1])] : null,
            $p[0] === 'MarketData' && ($p[1] ?? '') === 'GetStaticThreshold' => $this->known($code) ? ['staticThreshold' => $this->threshold($code)] : null,
            $p[0] === 'Index' && ($p[1] ?? '') === 'GetIndexB1LastAll' => ['indexB1' => $this->indices((int) ($p[3] ?? 1))],
            $p[0] === 'Index' && ($p[1] ?? '') === 'GetIndexB2History' => ['indexB2' => $this->indexHistory()],
            $p[0] === 'Index' && ($p[1] ?? '') === 'GetInstEffect' => ['instEffect' => $this->indexEffect((int) ($p[4] ?? 10))],
            $p[0] === 'Msg' && ($p[1] ?? '') === 'GetMsgByInsCode' => $this->known($code) ? ['msg' => $this->messages($code)] : null,
            $p[0] === 'Codal' && ($p[1] ?? '') === 'GetPreparedDataByInsCode' => $this->known($p[3] ?? '')
                ? ['preparedData' => array_slice($this->codal((string) $p[3]), 0, max(1, (int) $p[2]))] : null,
            $p[0] === 'Fund' && ($p[1] ?? '') === 'GetETFByInsCode' => $this->known($code) && ($nav = $this->nav($code)) ? ['etf' => $nav] : null,
            $p[0] === 'Fund' && ($p[1] ?? '') === 'GetFunds' => ['funds' => $this->funds((int) ($p[2] ?? 0))],
            str_contains($path, 'summary-table-data/') => ['data' => $this->tgjuRows(basename($path))],
            str_contains($path, 'PublicData/MarketDate') => [['isOpen' => MarketClock::isTradingDay($this->now())]],
            str_contains($path, 'MarketWatch/MarketWatchCash') => ['Items' => $this->marketWatch()],
            str_contains($path, 'MarketWatch/') => ['Items' => []],
            str_contains($path, 'MarketSummary') => [],
            default => null,
        };
    }

    // --------------------------------------------------------------- universe

    /** @return array<string, array<int, mixed>> insCode => definition */
    private static function universe(): array
    {
        if (!isset(self::$memo['universe'])) {
            $u = [];
            foreach (self::UNIVERSE as $i => $def) {
                $u[(string) (71000000000000000 + 1000003 * ($i + 1))] = $def;
            }
            self::$memo['universe'] = $u;
        }
        return self::$memo['universe'];
    }

    private function known(string $code): bool
    {
        return isset(self::universe()[$code]);
    }

    /** @return list<array<string, mixed>> */
    private function search(string $term): array
    {
        $q = Text::normalizeSymbol($term);
        $out = [];
        foreach (self::universe() as $key => $d) {
            $code = (string) $key;  // numeric-string keys become ints
            if ($q !== '' && (str_contains(Text::normalizeSymbol($d[0]), $q) || str_contains(Text::normalizeSymbol($d[1]), $q))) {
                $out[] = ['insCode' => $code, 'lVal18AFC' => $d[0], 'lVal30' => $d[1], 'cIsin' => $d[3], 'flowTitle' => $d[4],
                          'lastDate' => 0, 'flow' => $d[4] === 'بورس' ? 1 : 2];
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function info(string $code): array
    {
        $d = self::universe()[$code];
        $price = $this->current($code)['last'];
        $eps = $d[9] ? round($price * $d[9]) : null;
        return [
            'insCode' => $code, 'lVal18AFC' => $d[0], 'lVal30' => $d[1], 'cIsin' => $d[3], 'flowTitle' => $d[4],
            'sector' => ['cSecVal' => (string) (abs(crc32($d[2])) % 90 + 10), 'lSecVal' => $d[2]],
            'subSector' => ['cSoSecVal' => abs(crc32($d[15] ?? $d[2])) % 9000 + 1000, 'lSoSecVal' => $d[15] ?? $d[2]],
            'eps' => ['epsValue' => $eps, 'estimatedEPS' => $eps, 'sectorPE' => $d[10] ?: null],
            'zTitad' => $d[11] * 1e6, 'baseVol' => 100000,
        ];
    }

    // ---------------------------------------------------------------- calendar

    /** @return list<string> generated sessions (Y-m-d) from START through the last trading day up to today */
    private function sessions(): array
    {
        $key = 'sessions:' . MarketClock::day($this->now());
        if (!isset(self::$memo[$key])) {
            $out = [];
            $end = MarketClock::local($this->now(), 'Y-m-d');
            for ($t = strtotime(self::START . ' 12:00:00 UTC'); gmdate('Y-m-d', $t) <= $end; $t += 86400) {
                $date = gmdate('Y-m-d', $t);
                $dow = (int) gmdate('N', $t);
                [$y, $m, $dd] = array_map('intval', explode('-', $date));
                [, $jm, $jd] = Jalali::fromGregorian($y, $m, $dd);
                if (in_array($dow, MarketClock::TRADING_DAYS, true) && !($jm === 1 && $jd <= 4)) {
                    $out[] = $date;
                }
            }
            self::$memo[$key] = $out;
        }
        return self::$memo[$key];
    }

    /** Is the last generated session today, and how far into it are we? */
    private function today(): array
    {
        $now = $this->now();
        $sessions = $this->sessions();
        $isToday = end($sessions) === MarketClock::local($now, 'Y-m-d');
        $minute = MarketClock::minuteOfDay($now);
        return [
            'is_today' => $isToday,
            'pre_open' => $isToday && $minute >= 525 && $minute < 540,
            'before_open' => $isToday && $minute < 540,
            'progress' => $isToday ? MarketClock::sessionProgress($now) : 1.0,
            'final' => !$isToday || $minute >= 750,
            'minute' => $minute,
        ];
    }

    // ----------------------------------------------------------------- series

    /** Park–Miller generator: deterministic uniform numbers without touching mt_rand's global state. */
    private static function uniform(int &$state): float
    {
        $state = ($state * 48271) % 2147483647;
        return $state / 2147483647;
    }

    private static function normal(int &$state): float
    {
        $u = max(1e-12, self::uniform($state));
        $v = self::uniform($state);
        return sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }

    private static function seed(string $text): int
    {
        return (crc32($text) % 2147483646) + 1;
    }

    /** @return list<float> market factor daily returns, one per session */
    private function marketFactor(): array
    {
        $sessions = $this->sessions();
        $key = 'market:' . count($sessions);
        if (!isset(self::$memo[$key])) {
            $state = self::seed('market');
            $usd = $this->usdReturns();
            $out = [];
            $regime = 0.0;
            foreach ($sessions as $i => $_) {
                if ($i % 60 === 0) {
                    $regime = (self::uniform($state) - 0.42) * 0.004;  // slow bull/bear phases
                }
                // The free-market dollar drives Tehran: a weaker rial lifts exporters and the index.
                $out[] = $regime + 0.0005 + 0.35 * $usd[$i] + 0.010 * self::normal($state);
            }
            self::$memo[$key] = $out;
        }
        return self::$memo[$key];
    }

    /** @return list<float> daily free-market USD returns, one per session */
    private function usdReturns(): array
    {
        $sessions = $this->sessions();
        $key = 'usd:' . count($sessions);
        if (!isset(self::$memo[$key])) {
            $state = self::seed('usd');
            $out = [];
            foreach ($sessions as $i => $_) {
                $jump = self::uniform($state) < 0.02 ? 0.03 * (self::uniform($state) < 0.75 ? 1 : -1) : 0.0;  // occasional shocks
                $out[] = 0.0009 + 0.009 * self::normal($state) + $jump;
            }
            self::$memo[$key] = $out;
        }
        return self::$memo[$key];
    }

    /** Sensitivity of a sector to the dollar beyond the market's: exporters gain, importers lose. */
    private static function usdTilt(string $sector): float
    {
        return match (true) {
            str_contains($sector, 'طلا') => 0.85,   // gold in rial = world gold × dollar
            str_contains($sector, 'فلزات'), str_contains($sector, 'کانه'), str_contains($sector, 'شیمیایی'),
            str_contains($sector, 'فراورده') => 0.45,
            str_contains($sector, 'خودرو'), str_contains($sector, 'بانک') => -0.15,
            default => 0.0,
        };
    }

    /** Gregorian maturity of a demo treasury bill, from the YYMMDD at the end of its name. */
    private static function billMaturity(array $d): ?string
    {
        return str_starts_with($d[3], 'IRB') ? \Sabadgardan\Market\TreasuryYield::maturity($d[0], $d[1]) : null;
    }

    /**
     * Full-day OHLCV rows for every session (today's row is the full-day plan; partial views
     * of it are derived in current()).
     *
     * @return list<array{date: string, open: float, high: float, low: float, close: float, yesterday: float,
     *                    volume: float, value: float, trades: int, ret: float, buy_i: float, sell_i: float,
     *                    buy_n: float, sell_n: float, count_bi: int, count_si: int}>
     */
    private function series(string $code): array
    {
        $sessions = $this->sessions();
        $key = "series:$code:" . count($sessions);
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $d = self::universe()[$code];
        [, , , , , $endPrice, $beta, $vol, $drift] = $d;
        $bonus = $d[12];
        $divMonth = $d[13];
        $dps = $d[14];
        $index = array_search($code, array_map('strval', array_keys(self::universe())), true);
        $n = count(self::UNIVERSE);
        $state = self::seed($code);
        $m = $this->marketFactor();
        $usd = $this->usdReturns();
        $tilt = self::usdTilt($d[2] . ' ' . $d[1]);
        $isFund = $vol < 0.002;  // fixed-income funds and bonds: a smooth, almost risk-free accrual
        if ($maturity = self::billMaturity($d)) {
            $days = (strtotime($maturity) - strtotime(end($sessions))) / 86400;
            $endPrice = round(\Sabadgardan\Market\TreasuryYield::FACE / (1 + $drift) ** (max($days, 1) / 365));
        }

        // Raw path of returns first, then scale so the last close lands near the configured price.
        $rets = [];
        foreach ($sessions as $i => $date) {
            $r = $isFund ? 0.00095 + 0.0008 * self::normal($state)
                : $beta * $m[$i] + $tilt * $usd[$i] + $drift / 250 - 0.0008 + $vol * self::normal($state);
            if (!$isFund && $i % $n === $index) {
                $r = self::LIMIT;                       // today's limit-up (buy queue) symbol
            } elseif (!$isFund && ($i * 7 + 3) % $n === $index) {
                $r = -self::LIMIT;                      // today's limit-down (sell queue) symbol
            }
            $rets[] = max(-self::LIMIT, min(self::LIMIT, $r));
        }
        $level = 1.0;
        $adj = 1.0;
        foreach ($rets as $i => $r) {
            if (isset($bonus[$i])) {
                $adj /= 1 + $bonus[$i];
            }
            $level *= 1 + $r;
        }
        $close = $endPrice / ($level * $adj);
        $rows = [];
        $avgVolume = $d[11] * 1e6 * 0.0004;
        $paidYear = 0;
        foreach ($sessions as $i => $date) {
            $yesterday = $close;
            if (isset($bonus[$i])) {
                $yesterday = $close / (1 + $bonus[$i]);  // capital increase: reference price adjusted
            }
            [$y, $mo, $dd] = array_map('intval', explode('-', $date));
            [$jy, $jm, $jd] = Jalali::fromGregorian($y, $mo, $dd);
            if ($dps > 0 && $jm === $divMonth && $jd >= 10 && $paidYear !== $jy && $i > 0) {
                $yesterday = $close * (1 - $dps);       // cash dividend (once a year): reference price lowered by DPS
                $paidYear = $jy;
            }
            $r = $rets[$i];
            // Like the exchange: limits from the (rounded) reference price, every price inside them.
            $yesterday = round($yesterday);
            $upper = floor($yesterday * (1 + self::LIMIT));
            $lower = ceil($yesterday * (1 - self::LIMIT));
            $clip = static fn (float $p): float => max($lower, min($upper, round($p)));
            $c = $clip($yesterday * (1 + $r));
            $spread = $isFund ? 0.0008 : abs(self::normal($state)) * $vol * 0.6 + 0.004;
            $open = $clip($yesterday * (1 + $r * 0.4 + 0.006 * self::normal($state)));
            $high = $clip(max($open, $c) * (1 + $spread));
            $low = $clip(min($open, $c) * (1 - $spread));
            $volume = round($avgVolume * exp(0.45 * self::normal($state)) * (1 + 6 * abs($r)) * ($isFund ? 0.3 : 1));
            $power = exp($r * 18 + 0.25 * self::normal($state));
            $countBuy = max(20, (int) round(($isFund ? 40 : 900) * exp(0.4 * self::normal($state))));
            $countSell = max(20, (int) round($countBuy * $power));
            $indShare = 0.55 + 0.3 * self::uniform($state);
            $buyI = round($volume * $indShare * min(1.6, max(0.4, 1 + $r * 8)));
            $buyI = min($buyI, $volume);
            $sellI = round($volume * $indShare);
            $rows[$i] = [
                'date' => $date, 'open' => (float) $open, 'high' => (float) max($high, $c, $open), 'low' => (float) min($low, $c, $open),
                'close' => (float) $c, 'yesterday' => (float) round($yesterday), 'volume' => (float) $volume,
                'value' => (float) round($volume * ($c + $yesterday) / 2), 'trades' => max(10, (int) round($volume / 4000)),
                'ret' => $r, 'buy_i' => (float) $buyI, 'sell_i' => (float) min($sellI, $volume), 'buy_n' => (float) ($volume - $buyI),
                'sell_n' => (float) ($volume - min($sellI, $volume)), 'count_bi' => (int) round($countBuy * $buyI / max($sellI, 1)),
                'count_si' => $countSell,
            ];
            $close = $c;
        }
        return self::$memo[$key] = array_values($rows);
    }

    /** Finished sessions visible in the daily history (today only after the close). @return list<array> */
    private function finished(string $code): array
    {
        $rows = $this->series($code);
        if (!$this->today()['final']) {
            array_pop($rows);
        }
        return $rows;
    }

    /** Today's view of the market for a symbol, following the clock. @return array<string, float> */
    private function current(string $code): array
    {
        $rows = $this->series($code);
        $t = $this->today();
        $row = end($rows);
        if ($t['final']) {
            return ['last' => $row['close'], 'close' => $row['close'], 'yesterday' => $row['yesterday'], 'high' => $row['high'],
                    'low' => $row['low'], 'open' => $row['open'], 'volume' => $row['volume'], 'value' => $row['value'],
                    'trades' => $row['trades'], 'share' => 1.0, 'ret' => $row['ret']];
        }
        $y = $row['yesterday'];
        if ($t['before_open']) {
            return ['last' => $y, 'close' => $y, 'yesterday' => $y, 'high' => 0.0, 'low' => 0.0, 'open' => 0.0, 'volume' => 0.0,
                    'value' => 0.0, 'trades' => 0, 'share' => 0.0, 'ret' => $row['ret']];
        }
        $p = $t['progress'];
        $shape = min(1.0, 0.35 + 0.65 * $p);                 // most of the move happens early
        $state = self::seed($code . ':' . $t['minute']);
        $wiggle = 0.004 * self::normal($state) * (1 - $p);
        $r = max(-self::LIMIT, min(self::LIMIT, $row['ret'] * $shape + ($row['ret'] === self::LIMIT || $row['ret'] === -self::LIMIT ? 0 : $wiggle)));
        $last = max(ceil($y * (1 - self::LIMIT)), min(floor($y * (1 + self::LIMIT)), round($y * (1 + $r))));
        $closeNow = max(ceil($y * (1 - self::LIMIT)), min(floor($y * (1 + self::LIMIT)), round($y * (1 + $r * 0.9))));
        return ['last' => $last, 'close' => $closeNow, 'yesterday' => $y, 'high' => max($last, $row['open']),
                'low' => min($last, $row['open']), 'open' => $row['open'], 'volume' => round($row['volume'] * $p),
                'value' => round($row['value'] * $p), 'trades' => (int) round($row['trades'] * $p), 'share' => $p, 'ret' => $r];
    }

    // -------------------------------------------------------------- endpoints

    /** @return list<array<string, mixed>> newest first, like the real endpoint */
    private function dailyList(string $code, int $top): array
    {
        $out = [];
        foreach (array_reverse($this->finished($code)) as $r) {
            $out[] = ['insCode' => $code, 'dEven' => (int) str_replace('-', '', $r['date']), 'priceFirst' => $r['open'],
                      'priceMax' => $r['high'], 'priceMin' => $r['low'], 'pClosing' => $r['close'], 'pDrCotVal' => $r['close'],
                      'priceYesterday' => $r['yesterday'], 'priceChange' => $r['close'] - $r['yesterday'],
                      'qTotTran5J' => $r['volume'], 'qTotCap' => $r['value'], 'zTotTran' => $r['trades']];
            if ($top > 0 && count($out) >= $top) {
                break;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function priceInfo(string $code): array
    {
        $c = $this->current($code);
        $sessions = $this->sessions();
        return [
            'insCode' => $code, 'pDrCotVal' => $c['last'], 'pClosing' => $c['close'], 'priceYesterday' => $c['yesterday'],
            'priceFirst' => $c['open'], 'priceMax' => $c['high'], 'priceMin' => $c['low'], 'qTotTran5J' => $c['volume'],
            'qTotCap' => $c['value'], 'zTotTran' => $c['trades'], 'priceChange' => $c['close'] - $c['yesterday'],
            'instrumentState' => ['cEtaval' => 'A', 'cEtavalTitle' => 'مجاز'],
            'finalLastDate' => (int) str_replace('-', '', (string) end($sessions)),   // last session with trades
            'hEven' => (int) MarketClock::local($this->now(), 'His'),
        ];
    }

    /** @return array<string, float|int> */
    private function clientToday(string $code): array
    {
        $rows = $this->series($code);
        $row = end($rows);
        $share = $this->current($code)['share'];
        return [
            'buy_I_Volume' => round($row['buy_i'] * $share), 'sell_I_Volume' => round($row['sell_i'] * $share),
            'buy_N_Volume' => round($row['buy_n'] * $share), 'sell_N_Volume' => round($row['sell_n'] * $share),
            'buy_CountI' => (int) round($row['count_bi'] * max($share, 0.05)), 'sell_CountI' => (int) round($row['count_si'] * max($share, 0.05)),
            'buy_CountN' => (int) round(4 * $share), 'sell_CountN' => (int) round(3 * $share),
        ];
    }

    /** @return list<array<string, mixed>> newest first */
    private function clientHistory(string $code): array
    {
        $out = [];
        foreach (array_slice(array_reverse($this->finished($code)), 0, 300) as $r) {
            $out[] = ['recDate' => (int) str_replace('-', '', $r['date']), 'insCode' => $code,
                      'buy_I_Volume' => $r['buy_i'], 'sell_I_Volume' => $r['sell_i'], 'buy_N_Volume' => $r['buy_n'],
                      'sell_N_Volume' => $r['sell_n'], 'buy_I_Count' => $r['count_bi'], 'sell_I_Count' => $r['count_si'],
                      'buy_N_Count' => 3, 'sell_N_Count' => 3];  // the history endpoint's naming, unlike today's record
        }
        return $out;
    }

    /** Five order-book levels; limit moves show as queues (also during pre-open). @return list<array<string, float|int>> */
    private function bestLimits(string $code): array
    {
        $rows = $this->series($code);
        $row = end($rows);
        $c = $this->current($code);
        $y = $c['yesterday'];
        $upper = floor($y * (1 + self::LIMIT));
        $lower = ceil($y * (1 - self::LIMIT));
        $t = $this->today();
        $tick = max(1.0, round($y / 1000));
        $state = self::seed($code . ':book:' . $t['minute']);
        $depth = max(1000.0, $row['volume'] / 40);
        $levels = [];
        $active = !$t['is_today'] || !$t['before_open'] || $t['pre_open'];  // no orders before 8:45
        $queue = $active ? ($row['ret'] >= self::LIMIT ? 'buy' : ($row['ret'] <= -self::LIMIT ? 'sell' : '')) : '';
        for ($k = 1; $k <= 5; $k++) {
            $bid = $queue === 'sell' ? 0.0 : ($queue === 'buy' ? $upper - ($k - 1) * $tick : $c['last'] - $k * $tick);
            $ask = $queue === 'buy' ? 0.0 : ($queue === 'sell' ? $lower + ($k - 1) * $tick : $c['last'] + $k * $tick);
            $bidVol = $queue === 'buy' && $k === 1 ? $row['volume'] * 0.8 : $depth * (0.5 + self::uniform($state));
            $askVol = $queue === 'sell' && $k === 1 ? $row['volume'] * 0.8 : $depth * (0.5 + self::uniform($state));
            $levels[] = [
                'number' => $k,
                'pMeDem' => max(0.0, min($upper, $bid)), 'qTitMeDem' => $bid > 0 ? round($bidVol) : 0,
                'zOrdMeDem' => $bid > 0 ? ($queue === 'buy' && $k === 1 ? 1200 : 1 + (int) (self::uniform($state) * 12)) : 0,
                'pMeOf' => $ask > 0 ? max($lower, $ask) : 0.0, 'qTitMeOf' => $ask > 0 ? round($askVol) : 0,
                'zOrdMeOf' => $ask > 0 ? ($queue === 'sell' && $k === 1 ? 900 : 1 + (int) (self::uniform($state) * 12)) : 0,
            ];
        }
        return $levels;
    }

    /** @return list<array<string, float|int|string>> */
    private function threshold(string $code): array
    {
        $y = $this->current($code)['yesterday'];
        return [['insCode' => $code, 'psGelStaMax' => floor($y * (1 + self::LIMIT)), 'psGelStaMin' => ceil($y * (1 - self::LIMIT))]];
    }

    // ---------------------------------------------------------------- indices

    /** @return array<string, float> date => TEDPIX close, with today's live value last */
    private function tedpix(): array
    {
        $out = [];
        $level = 1_380_000.0;
        $sessions = $this->sessions();
        foreach ($this->marketFactor() as $i => $r) {
            $level *= 1 + max(-0.035, min(0.035, $r));
            $out[$sessions[$i]] = round($level);
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function indexHistory(): array
    {
        $series = $this->tedpix();
        if (!$this->today()['final']) {
            array_pop($series);
        }
        $out = [];
        foreach (array_reverse($series, true) as $date => $v) {
            $out[] = ['insCode' => self::TEDPIX, 'dEven' => (int) str_replace('-', '', $date), 'xNivInuClMresIbs' => $v,
                      'xNivInuPbMresIbs' => $v, 'xNivInuPhMresIbs' => $v];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function indices(int $flow): array
    {
        $series = array_values($this->tedpix());
        $t = $this->today();
        $last = end($series);
        $prev = $series[count($series) - 2];
        $share = $t['final'] ? 1.0 : ($t['before_open'] ? 0.0 : min(1.0, 0.35 + 0.65 * $t['progress']));
        $value = $prev + ($last - $prev) * $share;
        $chg = ($value / $prev - 1) * 100;
        $hEven = (int) MarketClock::local($this->now(), 'His');
        $mk = static fn (string $code, string $name, float $v, float $c) => ['insCode' => $code, 'lVal30' => $name,
            'xDrNivJIdx004' => round($v, 1), 'xVarIdxJRfV' => round($c, 2), 'xPhNivJIdx004' => round($v * 1.004, 1),
            'xPbNivJIdx004' => round($v * 0.995, 1), 'hEven' => $hEven];
        if ($flow === 2) {
            return [$mk('43685683301327984', 'شاخص کل فرابورس', $value * 0.0102, $chg * 1.2)];
        }
        return [
            $mk(self::TEDPIX, 'شاخص کل', $value, $chg),
            $mk('67130298613737946', 'شاخص کل (هم وزن)', $value * 0.29, $chg * 0.7),
            $mk('5798407779416661', 'شاخص قيمت (هم وزن)', $value * 0.19, $chg * 0.7),
            $mk('49579049405614711', 'شاخص آزاد شناور', $value * 1.45, $chg * 1.05),
            $mk('62752761908615603', 'شاخص بازار اول', $value * 0.74, $chg * 0.95),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function indexEffect(int $top): array
    {
        $rows = [];
        $series = $this->tedpix();
        $value = end($series) ?: 1.0;
        foreach (self::universe() as $key => $d) {
            $code = (string) $key;  // numeric-string keys become ints
            if (str_starts_with($d[3], 'IRT') || str_starts_with($d[3], 'IRB') || $d[4] !== 'بورس') {
                continue;
            }
            $c = $this->current($code);
            $cap = $c['last'] * $d[11] * 1e6;
            $rows[] = ['instrument' => ['insCode' => $code, 'lVal18AFC' => $d[0], 'lVal30' => $d[1]],
                       'instEffectValue' => round(($c['last'] / $c['yesterday'] - 1) * $cap / 1.5e17 * $value, 1)];
        }
        usort($rows, static fn ($a, $b) => abs($b['instEffectValue']) <=> abs($a['instEffectValue']));
        return array_slice($rows, 0, $top);
    }

    /** @return list<array<string, mixed>> */
    private function marketWatch(): array
    {
        $out = [];
        foreach (self::universe() as $key => $d) {
            $code = (string) $key;  // numeric-string keys become ints
            if (str_starts_with($d[3], 'IRT') || str_starts_with($d[3], 'IRB')) {
                continue;
            }
            $c = $this->current($code);
            // Only the documented fields (ENDPOINTS.md): the change percent is derived from the prices.
            $out[] = ['instrumentId' => $d[3], 'instrumentName' => $d[0], 'industryName' => $d[2], 'closingPrice' => $c['close'],
                      'yesterdayPrice' => $c['yesterday'], 'lastPrice' => $c['last'], 'tradeValue' => $c['value']];
        }
        $t = $this->today();
        $share = $t['final'] ? 1.0 : $t['progress'];
        $m = $this->marketFactor();
        $mt = end($m);
        foreach (self::WATCH_EXTRA as $i => [$name, $industry]) {
            $state = self::seed($name . ':' . MarketClock::day($this->now()));
            $chg = max(-5.0, min(5.0, ($mt * 1.1 + 0.018 * self::normal($state)) * 100 * min(1.0, 0.35 + 0.65 * $share)));
            $yesterday = round(2000 + 18000 * self::uniform($state));
            $out[] = ['instrumentId' => 'IRO1DEMO' . sprintf('%04d', $i), 'instrumentName' => $name, 'industryName' => $industry,
                      'closingPrice' => round($yesterday * (1 + $chg / 100)), 'yesterdayPrice' => $yesterday,
                      'tradeValue' => round(4e10 * exp(0.9 * self::normal($state)) * max($share, 0.02))];
        }
        return $out;
    }

    // ------------------------------------------------------ disclosures, NAV, USD

    /** Supervisor messages around capital increases and general meetings. @return list<array<string, mixed>> */
    private function messages(string $code): array
    {
        $d = self::universe()[$code];
        $sessions = $this->sessions();
        $out = [];
        foreach ($d[12] as $i => $ratio) {
            if (isset($sessions[$i - 1])) {
                $out[] = $this->msg($sessions[$i - 1], '0931', "{$d[0]} - افزایش سرمایه",
                    sprintf('به اطلاع می‌رساند افزایش سرمایه‌ی شرکت %s به میزان %d درصد از محل سود انباشته در اداره‌ی ثبت شرکت‌ها به ثبت رسیده است.', $d[1], $ratio * 100));
            }
        }
        $state = self::seed($code . ':msg');
        // Meetings halt a symbol for a session; a recent one is rarer, as on the real market.
        foreach ([90 => 0.45, 30 => 0.6, 4 => 0.85] as $ago => $skip) {
            $i = count($sessions) - 1 - $ago - (int) (self::uniform($state) * 3);
            if ($i < 1 || self::uniform($state) < $skip) {
                continue;
            }
            $out[] = $this->msg($sessions[$i], '0815', "توقف نماد {$d[0]}", "نماد {$d[0]} جهت برگزاری مجمع عمومی عادی سالیانه متوقف شد.");
            $out[] = $this->msg($sessions[$i + 1] ?? $sessions[$i], '0900', "بازگشایی نماد {$d[0]}", "نماد {$d[0]} پس از برگزاری مجمع بازگشایی می‌شود.");
        }
        usort($out, static fn ($a, $b) => [$b['dEven'], $b['hEven']] <=> [$a['dEven'], $a['hEven']]);
        return $out;
    }

    private function msg(string $date, string $hm, string $title, string $desc): array
    {
        return ['dEven' => (int) str_replace('-', '', $date), 'hEven' => (int) ($hm . '00'), 'tseTitle' => $title, 'tseDesc' => $desc, 'flow' => 1];
    }

    /** Monthly activity reports, quarterly statements and meeting notices, newest first. @return list<array<string, mixed>> */
    private function codal(string $code): array
    {
        $d = self::universe()[$code];
        if (str_starts_with($d[3], 'IRB')) {
            return [];
        }
        $fund = str_starts_with($d[3], 'IRT');
        [$jy, $jm] = Jalali::fromGregorian(...array_map('intval', explode('-', MarketClock::local($this->now(), 'Y-m-d'))));
        $out = [];
        for ($k = 1; $k <= 12; $k++) {
            $pm = $jm - $k;
            $py = $jy;
            while ($pm < 1) {
                $pm += 12;
                $py--;
            }
            $last = $pm <= 6 ? 31 : ($pm < 12 ? 30 : 29);
            $end = sprintf('%04d/%02d/%02d', $py, $pm, $last);
            [$ny, $nm] = $pm === 12 ? [$py + 1, 1] : [$py, $pm + 1];
            $publish = sprintf('%04d/%02d/%02d 18:%02d:00', $ny, $nm, 9 + $k % 5, 10 + $k);
            $out[] = $this->letter($code, $fund ? "صورت وضعیت پرتفوی صندوق ماهانه منتهی به $end" : "گزارش فعالیت ماهانه دوره ۱ ماهه منتهی به $end", $publish);
            if (!$fund && in_array($pm, [3, 6, 9, 12], true)) {
                $out[] = $this->letter($code, "اطلاعات و صورت‌های مالی میاندوره‌ای دوره ۳ ماهه منتهی به $end (حسابرسی نشده)",
                    sprintf('%04d/%02d/%02d 20:05:00', $ny, $nm, 25));
            }
            if (!$fund && $d[13] && $nm === $d[13]) {
                $out[] = $this->letter($code, 'آگهی دعوت به مجمع عمومی عادی سالیانه صاحبان سهام', sprintf('%04d/%02d/%02d 16:40:00', $ny, $nm, 2));
            }
        }
        usort($out, static fn ($a, $b) => strcmp($b['publishDateTime'], $a['publishDateTime']));
        return $out;
    }

    private function letter(string $code, string $title, string $publish): array
    {
        $serial = substr(sha1($code . $title), 0, 12);
        return ['symbol' => self::universe()[$code][0], 'name' => self::universe()[$code][1], 'title' => $title,
                'letterCode' => 'ن-۳۰', 'sentDateTime' => $publish, 'publishDateTime' => $publish,
                'url' => "/Reports/Decision.aspx?LetterSerial=DEMO$serial", 'tracingNo' => abs(crc32($serial))];
    }

    /** NAVs of the demo funds: leveraged funds trade at a premium after rallies. @return array<string, float>|null */
    private function nav(string $code): ?array
    {
        $d = self::universe()[$code];
        if (!str_starts_with($d[3], 'IRT')) {
            return null;
        }
        $close = $this->current($code)['close'];
        $premium = match (true) {
            str_contains($d[1], 'اهرم') => 0.083,
            str_contains($d[1], 'طلا') => 0.021,
            default => -0.002,
        };
        $red = round($close / (1 + $premium));
        $sessions = $this->sessions();
        return ['insCode' => $code, 'navRed' => $red, 'navSub' => round($red * 1.004), 'navStat' => round($red * 1.002),
                'deven' => (int) str_replace('-', '', (string) end($sessions))];
    }

    /** Fund registry of one type (Fund/GetFunds/{type}), as fipiran publishes it. @return list<array<string, mixed>> */
    private function funds(int $type): array
    {
        $out = [];
        foreach (self::universe() as $code => $d) {
            $class = \Sabadgardan\Market\AssetClass::classify($d[3], $d[1], $d[0], $d[2], $d[15] ?? '');
            if ((\Sabadgardan\Market\FundNav::REGISTRY_TYPES[$class->kind] ?? null) !== $type || !($nav = $this->nav((string) $code))) {
                continue;
            }
            $rows = $this->series((string) $code);
            $last = end($rows)['close'];
            $yearAgo = $rows[max(0, count($rows) - 251)]['close'];
            $out[] = ['regNo' => (string) (11000 + $d[11]), 'mfName' => $d[1], 'insCode' => (string) $code] + $nav
                + ['netAsset' => round($nav['navRed'] * $d[11] * 1e5), 'day365Return' => round(($last / $yearAgo - 1) * 100, 2)];
        }
        return $out;
    }

    /** tgju rows, newest first: [open, low, high, close, change, change %, Y/m/d, jalali]. @return list<list<string>> */
    private function tgjuRows(string $slug): array
    {
        if (!in_array($slug, ['price_dollar_rl', 'sekee'], true)) {
            return [];
        }
        $level = $slug === 'sekee' ? 330_000_000.0 : 1_030_000.0;
        $rets = $this->usdReturns();
        $sessions = $this->sessions();
        $prices = [];
        for ($i = count($rets) - 1; $i >= 0; $i--) {
            $prices[$i] = $level;
            $level /= 1 + $rets[$i] * ($slug === 'sekee' ? 1.1 : 1.0);
        }
        $rows = [];
        $fmt = static fn (float $v): string => number_format(round($v / 10) * 10);
        for ($i = count($rets) - 1; $i >= 0; $i--) {
            $c = $prices[$i];
            $o = $prices[$i - 1] ?? $c;
            [$y, $m, $dd] = array_map('intval', explode('-', $sessions[$i]));
            $rows[] = [$fmt($o), $fmt(min($o, $c) * 0.995), $fmt(max($o, $c) * 1.005), $fmt($c), $fmt(abs($c - $o)),
                       round(($c / $o - 1) * 100, 2) . '%', str_replace('-', '/', $sessions[$i]), vsprintf('%04d/%02d/%02d', Jalali::fromGregorian($y, $m, $dd))];
        }
        return $rows;
    }
}
