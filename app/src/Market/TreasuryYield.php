<?php

declare(strict_types=1);

namespace Sabadgardan\Market;

/**
 * Market risk-free rate from Islamic treasury bills (اسناد خزانه، «اخزا»): zero-coupon, redeemed at
 * 1,000,000 rial on maturity, so yield to maturity = (1,000,000 / price)^(365 / days) − 1.
 *
 * Maturity is encoded as YYMMDD (Jalali): after the last «-» of the name in current series
 * («اسناد خزانه-م2بودجه04-070614» → 1407/06/14) or right after «اخزا» in older symbols
 * («اخزا020322» → 1402/03/22); YY 00–79 means 14YY. Bills with 60 to 730 days left that traded
 * in the last two weeks are used; the rate is their median yield.
 */
final class TreasuryYield
{
    public const FACE = 1_000_000.0;
    public const MIN_DAYS = 60;
    public const MAX_DAYS = 730;

    /** Jalali maturity as Gregorian Y-m-d, or null. */
    public static function maturity(string $symbol, string $name): ?string
    {
        $symbol = Text::normalizeDigits(Text::normalizeSymbol($symbol));
        $name = Text::normalizeDigits($name);
        $code = null;
        if (preg_match('/-\s*(\d{6})\s*(?:\(|$)/u', trim($name), $m)) {
            $code = $m[1];
        } elseif (preg_match('/^اخزا(\d{6})$/u', $symbol, $m)) {
            $code = $m[1];
        }
        if ($code === null) {
            return null;
        }
        $yy = (int) substr($code, 0, 2);
        $jy = $yy <= 79 ? 1400 + $yy : 1300 + $yy;
        $jm = (int) substr($code, 2, 2);
        $jd = (int) substr($code, 4, 2);
        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
            return null;
        }
        [$gy, $gm, $gd] = Jalali::toGregorian($jy, $jm, $jd);
        // Reject impossible dates (e.g. 1404/12/31 in a non-leap year) by round-tripping.
        return Jalali::fromGregorian($gy, $gm, $gd) === [$jy, $jm, $jd] ? sprintf('%04d-%02d-%02d', $gy, $gm, $gd) : null;
    }

    public static function ytm(float $price, int $days): ?float
    {
        if ($price <= 0 || $price >= self::FACE * 1.0001 || $days <= 0) {
            return null;
        }
        return (self::FACE / $price) ** (365 / $days) - 1;
    }

    /**
     * @return array{rate: float, bills: list<array{symbol: string, maturity: string, days: int, price: float, ytm: float}>}|null
     */
    public static function fromMarket(MarketData $market, string $today): ?array
    {
        $candidates = [];
        foreach ($market->search('اخزا') as $row) {
            $isin = strtoupper((string) $row['isin']);
            $maturity = self::maturity((string) $row['symbol'], (string) $row['name']);
            if ($maturity === null || ($isin !== '' && !str_starts_with($isin, 'IRB'))) {
                continue;
            }
            $days = (int) round((strtotime($maturity . ' UTC') - strtotime($today . ' UTC')) / 86400);
            if ($days >= self::MIN_DAYS && $days <= self::MAX_DAYS) {
                $candidates[(string) $row['ins_code']] = ['symbol' => (string) $row['symbol'], 'maturity' => $maturity, 'days' => $days];
            }
        }
        if (!$candidates) {
            return null;
        }
        $bills = [];
        // Daily history too: a bill that hasn't traded for two weeks quotes a stale price, not today's yield.
        foreach ($market->bundles(array_keys($candidates), ['price', 'history']) as $code => $b) {
            $c = $candidates[(string) $code];
            $price = $b->closePrice();
            $lastTrade = $b->raw->dates ? end($b->raw->dates) : '';
            $stale = $lastTrade === '' || strtotime($lastTrade) < strtotime($today) - 14 * 86400;
            $y = $price ? self::ytm($price, $c['days']) : null;
            if ($y !== null && !$stale && $y > 0.05 && $y < 1.5) {
                $bills[] = $c + ['price' => (float) $price, 'ytm' => $y];
            }
        }
        if (!$bills) {
            return null;
        }
        usort($bills, static fn ($a, $b) => $a['days'] <=> $b['days']);
        $yields = array_column($bills, 'ytm');
        sort($yields);
        $n = count($yields);
        $median = $n % 2 ? $yields[intdiv($n, 2)] : ($yields[$n / 2 - 1] + $yields[$n / 2]) / 2;
        return ['rate' => $median, 'bills' => $bills];
    }
}
