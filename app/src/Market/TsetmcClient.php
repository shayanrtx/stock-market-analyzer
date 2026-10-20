<?php

declare(strict_types=1);

namespace Sabadgardan\Market;

use Sabadgardan\Core\Cache;
use Sabadgardan\Market\Http\HttpResult;
use Sabadgardan\Market\Http\Transport;

/**
 * Client for the unofficial TSETMC CDN API (https://cdn.tsetmc.com/api).
 * Reference: https://github.com/BabakEslami/tse-market-data/blob/main/ENDPOINTS.md
 *
 * - A browser-like User-Agent/Referer/Origin is required, otherwise HTTP 403.
 * - 429/502/503/504 and network errors are retried with exponential backoff.
 * - Unknown ids return empty payloads rather than errors.
 * Every call goes through the cache with a TTL that fits how fast that data changes.
 */
class TsetmcClient
{
    public const BASE_URL = 'https://cdn.tsetmc.com/api';
    public const TEDPIX = '32097828799138957';
    public const FLOW_BOURSE = 1;
    public const FLOW_FARABOURSE = 2;

    public const HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126 Safari/537.36',
        'Accept' => 'application/json,text/plain,*/*',
        'Referer' => 'https://www.tsetmc.com/',
        'Origin' => 'https://www.tsetmc.com',
    ];

    // Cache lifetimes (seconds).
    public const TTL_LIVE = 20;
    public const TTL_INTRADAY = 120;
    public const TTL_DAILY = 1800;
    public const TTL_STATIC = 86400;

    protected string $baseUrl = self::BASE_URL;

    public function __construct(
        protected Transport $transport,
        protected Cache $cache,
        protected int $timeout = 15,
        protected int $maxRetries = 2,
        protected float $backoff = 0.6,
    ) {
    }

    // ------------------------------------------------------------------ core

    /** GET one JSON endpoint. */
    public function get(string $path, int $ttl = self::TTL_INTRADAY): mixed
    {
        return $this->getMany([$path => $path], $ttl)[$path];
    }

    /**
     * GET several endpoints in parallel. Throws if any of them fails after retries.
     *
     * @param array<string, string> $paths key => path
     * @return array<string, mixed> key => decoded JSON
     */
    public function getMany(array $paths, int $ttl = self::TTL_INTRADAY): array
    {
        [$data, $errors] = $this->fetch($paths, $ttl);
        if ($errors) {
            throw new MarketException(implode(' | ', array_unique($errors)));
        }
        return $data;
    }

    /**
     * Like getMany() but never throws: failed keys map to null.
     *
     * @param array<string, string> $paths
     * @return array<string, mixed>
     */
    public function tryMany(array $paths, int $ttl = self::TTL_INTRADAY): array
    {
        [$data] = $this->fetch($paths, $ttl);
        foreach ($paths as $key => $_) {
            $data[$key] ??= null;
        }
        return $data;
    }

    /**
     * @param array<string, string> $paths
     * @return array{0: array<string, mixed>, 1: array<string, string>} data and errors, keyed like $paths
     */
    private function fetch(array $paths, int $ttl): array
    {
        $out = [];
        $pending = [];
        foreach ($paths as $key => $path) {
            $url = $this->url($path);
            $hit = $this->cache->get($url);
            if ($hit !== null) {
                $out[$key] = $hit;
            } else {
                $pending[$key] = $url;
            }
        }
        $attempt = 0;
        $errors = [];
        while ($pending) {
            $results = $this->transport->fetchMany(array_values($pending), $this->headers(), $this->timeout);
            $retry = [];
            foreach ($pending as $key => $url) {
                $res = $results[$url] ?? new HttpResult(0, '', 'no response');
                if ($res->ok()) {
                    $decoded = $res->body === '' ? [] : json_decode($res->body, true);
                    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                        $errors[$key] = 'پاسخ نامعتبر از سرور بورس';
                        continue;
                    }
                    $out[$key] = $decoded;
                    $this->cache->set($url, $decoded, $ttl);
                } elseif ($res->isTransient() && $attempt < $this->maxRetries) {
                    $retry[$key] = $url;
                } else {
                    $errors[$key] = $this->describe($res);
                }
            }
            $pending = $retry;
            if ($pending) {
                usleep((int) ($this->backoff * (2 ** $attempt) * 1_000_000));
                $attempt++;
            }
        }
        return [$out, $errors];
    }

    protected function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    /** @return array<string, string> */
    protected function headers(): array
    {
        return self::HEADERS;
    }

    protected function describe(HttpResult $res): string
    {
        if ($res->status === 0) {
            return 'اتصال به سرور بورس برقرار نشد' . ($res->error ? " ({$res->error})" : '');
        }
        if ($res->status === 403) {
            return 'سرور بورس دسترسی را رد کرد (403)؛ احتمالاً IP سرور خارج از ایران است یا مسدود شده';
        }
        return "خطای HTTP {$res->status} از سرور بورس";
    }

    // ----------------------------------------------------------- instruments

    /** @return list<array<string, mixed>> */
    public function search(string $query): array
    {
        $q = trim($query);
        if ($q === '') {
            return [];
        }
        $data = $this->get('Instrument/GetInstrumentSearch/' . rawurlencode($q), self::TTL_STATIC);
        return Field::list($data, 'instrumentSearch');
    }

    /** Best match for a symbol (exact symbol first); throws when nothing is found. */
    public function resolve(string $symbolOrInsCode): Instrument
    {
        $s = trim($symbolOrInsCode);
        if (ctype_digit($s)) {
            return new Instrument($s, $s);
        }
        $results = $this->search($s);
        if (!$results) {
            throw new MarketException("نماد «{$s}» پیدا نشد");
        }
        $norm = Text::normalizeSymbol($s);
        foreach ($results as $r) {
            if (Text::normalizeSymbol((string) Field::get($r, 'lVal18AFC', '')) === $norm) {
                return Instrument::fromSearch($r);
            }
        }
        return Instrument::fromSearch($results[0]);
    }

    public function dailyHistory(string $insCode, int $top = 0): PriceHistory
    {
        $data = $this->get("ClosingPrice/GetClosingPriceDailyList/$insCode/$top", self::TTL_DAILY);
        return PriceHistory::fromClosingPriceDaily(Field::list($data, 'closingPriceDaily'));
    }

    /** @return array<string, mixed> */
    public function closingPriceInfo(string $insCode): array
    {
        return (array) Field::get($this->get("ClosingPrice/GetClosingPriceInfo/$insCode", self::TTL_LIVE),
            'closingPriceInfo', []);
    }

    /** @return array<string, mixed> */
    public function instrumentInfo(string $insCode): array
    {
        return (array) Field::get($this->get("Instrument/GetInstrumentInfo/$insCode", self::TTL_STATIC),
            'instrumentInfo', []);
    }

    /** @return array<string, mixed> today's individual/institutional breakdown */
    public function clientType(string $insCode): array
    {
        return (array) Field::get($this->get("ClientType/GetClientType/$insCode/1/0", self::TTL_LIVE),
            'clientType', []);
    }

    /** @return list<array<string, mixed>> daily client-type history (verify fields with the health page) */
    public function clientTypeHistory(string $insCode): array
    {
        return Field::list($this->get("ClientType/GetClientTypeHistory/$insCode", self::TTL_DAILY), 'clientType');
    }

    /** @return list<array<string, mixed>> */
    public function bestLimits(string $insCode): array
    {
        return Field::list($this->get("BestLimits/$insCode", self::TTL_LIVE), 'bestLimits');
    }

    /** @return array{0: ?float, 1: ?float} allowed daily price range (upper, lower) */
    public function thresholds(string $insCode, string $ymd): array
    {
        $rows = Field::list($this->get("MarketData/GetStaticThreshold/$insCode/$ymd", self::TTL_STATIC),
            'staticThreshold');
        foreach (array_reverse($rows) as $row) {
            $hi = Field::float($row, 'psGelStaMax');
            $lo = Field::float($row, 'psGelStaMin');
            if ($hi && $lo) {
                return [$hi, $lo];
            }
        }
        return [null, null];
    }

    // ---------------------------------------------------------------- market

    /** @return list<array<string, mixed>> */
    public function indices(int $flow = self::FLOW_BOURSE, bool $selectedOnly = true): array
    {
        $kind = $selectedOnly ? 'SelectedIndexes' : 'All';
        return Field::list($this->get("Index/GetIndexB1LastAll/$kind/$flow", self::TTL_LIVE), 'indexB1');
    }

    /** @return array<string, float> date (Y-m-d) => closing value, oldest first */
    public function indexHistory(string $insCode = self::TEDPIX): array
    {
        $rows = Field::list($this->get("Index/GetIndexB2History/$insCode", self::TTL_DAILY), 'indexB2');
        $out = [];
        foreach ($rows as $r) {
            $day = (string) Field::get($r, 'dEven', '');
            $value = Field::float($r, ['xNivInuClMresIbs', 'xDrNivJIdx004', 'indexValue', 'close']);
            if (strlen($day) === 8 && $value !== null) {
                $out[substr($day, 0, 4) . '-' . substr($day, 4, 2) . '-' . substr($day, 6, 2)] = $value;
            }
        }
        ksort($out);
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function indexEffect(int $flow = self::FLOW_BOURSE, int $top = 10): array
    {
        return Field::list($this->get("Index/GetInstEffect/0/$flow/$top", self::TTL_INTRADAY), 'instEffect');
    }

    /** @return list<array<string, mixed>> */
    public function clientTypeAll(): array
    {
        return Field::list($this->get('ClientType/GetClientTypeAll', self::TTL_INTRADAY), 'clientTypeAllDto');
    }

    // ------------------------------------------------- disclosures and funds

    /** Supervisor messages (پیام ناظر): dEven, hEven, tseTitle, tseDesc. @return list<array<string, mixed>> */
    public function messages(string $insCode): array
    {
        return Field::list($this->get("Msg/GetMsgByInsCode/$insCode", self::TTL_INTRADAY), 'msg');
    }

    /** Codal announcements of a symbol, newest first. @return list<array<string, mixed>> */
    public function announcements(string $insCode, int $top = 20): array
    {
        return Field::list($this->get("Codal/GetPreparedDataByInsCode/$top/$insCode", self::TTL_INTRADAY), 'preparedData');
    }

    /**
     * ETF record with the published NAVs (works for funds only): pRedTran (redemption), pSubTran
     * (subscription), deven/hEven (when they were published).
     *
     * @return array<string, mixed>
     */
    public function etf(string $insCode): array
    {
        return (array) Field::get($this->get("Fund/GetETFByInsCode/$insCode", self::TTL_INTRADAY), 'etf', []);
    }

    /**
     * Fund registry by type (4 fixed income, 5 commodity, 6 equity, 7 mixed, 18 real estate,
     * 21 sector, 22 leveraged, 23 index): mfName, navRed, navSub, navStat, netAsset, dayNReturn, portfolio*.
     *
     * @return list<array<string, mixed>>
     */
    public function funds(int $type): array
    {
        return Field::list($this->get("Fund/GetFunds/$type", self::TTL_DAILY), 'funds');
    }
}
