<?php

declare(strict_types=1);

namespace Sabadgardan\Portfolio;

/**
 * Two honest answers to "how did I do?", both net of fees, for the holdings themselves (so they
 * don't depend on whether deposits were recorded):
 *
 * - Time-weighted return (TWR): the manager's skill. Each session's return is linked, with money
 *   put in counted from the start of that day: r = (V_t + out_t) / (V_{t−1} + in_t) − 1, where "in"
 *   is the cost of purchases and "out" the net proceeds of sales and the dividends received.
 *   Adding or withdrawing money doesn't change it, so it is the figure to compare with TEDPIX.
 * - Money-weighted return (XIRR): the investor's actual annual rate, which also rewards or
 *   punishes the timing and size of each purchase.
 */
final class Returns
{
    /** Shorter periods aren't annualised: +5% in a month is not a 79% annual return. */
    public const MIN_ANNUAL_DAYS = 180;

    /**
     * @param array<string, float> $values holdings value at each session close (date => rial)
     * @param list<Transaction> $txs
     * @return array<string, float> TWR growth index by date, starting at 100
     */
    public static function twrIndex(array $values, array $txs): array
    {
        ksort($values);
        $flows = self::sleeveFlows($txs);
        $index = [];
        $level = 100.0;
        $prev = 0.0;
        $k = 0;
        $flowDates = array_keys($flows);
        foreach ($values as $d => $v) {
            $in = $out = 0.0;
            // Flows on a day without a session (a weekend entry) count on the next session.
            while ($k < count($flowDates) && $flowDates[$k] <= $d) {
                $in += $flows[$flowDates[$k]]['in'];
                $out += $flows[$flowDates[$k]]['out'];
                $k++;
            }
            $base = $prev + $in;
            if ($base > 0) {
                $level *= ($v + $out) / $base;
            }
            $index[$d] = $level;
            $prev = $v;
        }
        return $index;
    }

    /**
     * Annual internal rate of return of dated cash flows (investor's view: purchases negative,
     * sales, dividends and the closing value positive). Newton's method with a bisection fallback.
     *
     * @param list<array{0: string, 1: float}> $flows [date Y-m-d, amount]
     */
    public static function xirr(array $flows): ?float
    {
        $flows = array_values(array_filter($flows, static fn ($f) => abs($f[1]) > 0));
        if (count($flows) < 2 || min(array_column($flows, 1)) >= 0 || max(array_column($flows, 1)) <= 0) {
            return null;
        }
        $t0 = strtotime(min(array_column($flows, 0)) . ' UTC');
        $years = array_map(static fn ($f) => (strtotime($f[0] . ' UTC') - $t0) / (365 * 86400), $flows);
        if (max($years) < 1 / 365) {
            return null;
        }
        $npv = static function (float $r) use ($flows, $years): float {
            $s = 0.0;
            foreach ($flows as $i => $f) {
                $s += $f[1] / (1 + $r) ** $years[$i];
            }
            return $s;
        };
        $r = 0.2;
        for ($i = 0; $i < 60; $i++) {
            $f = $npv($r);
            $h = 1e-6;
            $df = ($npv($r + $h) - $f) / $h;
            if ($df == 0.0 || !is_finite($df)) {
                break;
            }
            $next = $r - $f / $df;
            if (!is_finite($next) || $next <= -0.9999) {
                break;
            }
            if (abs($next - $r) < 1e-9) {
                return $next;
            }
            $r = $next;
        }
        // Bisection on [-99.99%, +10000%]: NPV falls as the rate rises for a normal investment.
        [$lo, $hi] = [-0.9999, 100.0];
        $flo = $npv($lo);
        if ($flo * $npv($hi) > 0) {
            return null;
        }
        for ($i = 0; $i < 200; $i++) {
            $mid = ($lo + $hi) / 2;
            $fm = $npv($mid);
            if (abs($fm) < 1e-6 || $hi - $lo < 1e-10) {
                return $mid;
            }
            if ($flo * $fm < 0) {
                $hi = $mid;
            } else {
                [$lo, $flo] = [$mid, $fm];
            }
        }
        return ($lo + $hi) / 2;
    }

    /**
     * Investor cash flows of the holdings, plus their value on the last date as if sold.
     *
     * @param list<Transaction> $txs
     * @return list<array{0: string, 1: float}>
     */
    public static function investorFlows(array $txs, string $lastDate, float $lastValue): array
    {
        $out = [];
        foreach ($txs as $t) {
            if (in_array($t->type, [Transaction::BUY, Transaction::SELL, Transaction::DIVIDEND], true) && $t->date <= $lastDate) {
                $out[] = [$t->date, $t->cashFlow()];
            }
        }
        $out[] = [$lastDate, $lastValue];
        return $out;
    }

    /** (1 + total)^(1/years) − 1, or null for periods too short to annualise honestly. */
    public static function annualise(float $totalReturn, float $days, float $minDays = self::MIN_ANNUAL_DAYS): ?float
    {
        return $days >= $minDays && $totalReturn > -1 ? (1 + $totalReturn) ** (365 / $days) - 1 : null;
    }

    /**
     * @param list<Transaction> $txs
     * @return array<string, array{in: float, out: float}> by date, sorted
     */
    private static function sleeveFlows(array $txs): array
    {
        $flows = [];
        foreach ($txs as $t) {
            $cash = match ($t->type) {
                Transaction::BUY, Transaction::SELL, Transaction::DIVIDEND => $t->cashFlow(),
                default => null,
            };
            if ($cash === null) {
                continue;
            }
            $flows[$t->date] ??= ['in' => 0.0, 'out' => 0.0];
            if ($cash < 0) {
                $flows[$t->date]['in'] -= $cash;
            } else {
                $flows[$t->date]['out'] += $cash;
            }
        }
        ksort($flows);
        return $flows;
    }
}
