<?php

declare(strict_types=1);

namespace Sabadgardan\Market;

use Sabadgardan\Market\Http\HttpResult;

/**
 * Free-market currency and coin prices from tgju.org (unofficial JSON used by its charts):
 * ``/v1/market/indicator/summary-table-data/{slug}`` → ``data``: rows of
 * [open, low, high, close, change, change %, gregorian Y/m/d, jalali], newest first, numbers as
 * text with thousands separators (sometimes wrapped in HTML). Prices are in rial.
 */
final class TgjuClient extends TsetmcClient
{
    public const BASE_URL = 'https://api.tgju.org/v1';
    public const USD = 'price_dollar_rl';
    public const COIN = 'sekee';
    public const FAILURE_TTL = 900;

    protected string $baseUrl = self::BASE_URL;

    /** @return array<string, float> date (Y-m-d) => close, oldest first */
    public function history(string $slug = self::USD): array
    {
        // A failure is remembered for a while, so an unreachable tgju doesn't cost every page a timeout.
        $down = "tgju-down:$slug";
        if ($this->cache->get($down)) {
            throw new MarketException('tgju.org به‌تازگی پاسخ نداده است.');
        }
        try {
            return self::parse(Field::list($this->get('market/indicator/summary-table-data/' . rawurlencode($slug), self::TTL_DAILY), 'data'));
        } catch (MarketException $e) {
            $this->cache->set($down, true, self::FAILURE_TTL);
            throw $e;
        }
    }

    /**
     * @param list<mixed> $rows
     * @return array<string, float>
     */
    public static function parse(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) < 7) {
                continue;
            }
            $row = array_values($row);
            $close = Text::parseNumber(strip_tags((string) $row[3]));
            $date = str_replace('/', '-', trim(strip_tags(Text::normalizeDigits((string) $row[6]))));
            if ($close && $close > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $out[$date] = $close;
            }
        }
        ksort($out);
        return $out;
    }

    protected function headers(): array
    {
        return ['User-Agent' => self::HEADERS['User-Agent'], 'Accept' => 'application/json,text/plain,*/*',
                'Referer' => 'https://www.tgju.org/', 'Origin' => 'https://www.tgju.org'];
    }

    protected function describe(HttpResult $res): string
    {
        return $res->status === 0 ? 'اتصال به tgju.org برقرار نشد' . ($res->error ? " ({$res->error})" : '')
            : "خطای HTTP {$res->status} از tgju.org";
    }
}
