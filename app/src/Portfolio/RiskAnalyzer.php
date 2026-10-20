<?php

declare(strict_types=1);

namespace Sabadgardan\Portfolio;

use Sabadgardan\Analysis\Stats;

/**
 * Risk and performance of the current holdings replayed over past adjusted prices — the
 * "what if I had held this basket" view a portfolio manager uses to judge the risk carried
 * today. Excess returns are measured against the risk-free rate (fixed-income funds), not zero.
 */
final class RiskAnalyzer
{
    public const TRADING_DAYS = 250;
    public const LIQUIDITY_PARTICIPATION = 0.20;  // share of daily value tradable without moving the price

    public function __construct(private float $riskFreeAnnual = 0.30)
    {
    }

    /**
     * @param array<string, array<string, float>> $values symbol => [date => position value]
     * @param array<string, float> $benchmark date => index close
     * @return array<string, mixed>|null
     */
    public function analyze(array $values, array $benchmark = [], int $window = self::TRADING_DAYS): ?array
    {
        if (!$values) {
            return null;
        }
        // Align on dates, forward-filling each symbol; keep only dates where all have traded.
        $dates = [];
        foreach ($values as $series) {
            $dates += array_fill_keys(array_keys($series), true);
        }
        ksort($dates);
        $symbols = array_keys($values);
        $last = array_fill_keys($symbols, null);
        $frame = [];
        foreach (array_keys($dates) as $d) {
            foreach ($symbols as $s) {
                if (isset($values[$s][$d])) {
                    $last[$s] = $values[$s][$d];
                }
            }
            if (!in_array(null, $last, true)) {
                $frame[$d] = $last;
            }
        }
        $frame = array_slice($frame, -($window + 1), null, true);
        if (count($frame) < 30) {
            return null;
        }
        $dateList = array_keys($frame);
        $total = array_map('array_sum', array_values($frame));
        $rets = Stats::returns($total);
        $assetRets = [];
        foreach ($symbols as $s) {
            $assetRets[$s] = Stats::returns(array_column(array_values($frame), $s));
        }

        $equity = $drawdown = [];
        $peak = 0.0;
        foreach ($total as $i => $v) {
            $equity[$dateList[$i]] = $v / $total[0] * 100;
            $peak = max($peak, $v);
            $drawdown[$dateList[$i]] = ($v / $peak - 1) * 100;
        }

        $rfDaily = (1 + $this->riskFreeAnnual) ** (1 / self::TRADING_DAYS) - 1;
        $excess = array_map(static fn ($r) => $r - $rfDaily, $rets);
        $downside = array_values(array_filter($excess, static fn ($r) => $r < 0));
        $std = Stats::std($rets);
        $years = count($rets) / self::TRADING_DAYS;
        $periodRet = end($total) / $total[0] - 1;
        $var95 = -Stats::quantile($rets, 0.05);
        $tail = array_filter($rets, static fn ($r) => $r <= -$var95);
        $m = [
            'return_period_pct' => $periodRet * 100,
            'return_annual_pct' => $years > 0 ? ((1 + $periodRet) ** (1 / $years) - 1) * 100 : null,
            'return_1m_pct' => Stats::pctReturn($total, 21),
            'return_3m_pct' => Stats::pctReturn($total, 63),
            'volatility_annual_pct' => $std * sqrt(self::TRADING_DAYS) * 100,
            'max_drawdown_pct' => min($drawdown),
            'current_drawdown_pct' => end($drawdown),
            'sharpe' => $std ? Stats::mean($excess) / $std * sqrt(self::TRADING_DAYS) : null,
            'sortino' => count($downside) > 2 && ($ds = Stats::std($downside))
                ? Stats::mean($excess) / $ds * sqrt(self::TRADING_DAYS) : null,
            'var95_1d_pct' => $var95 * 100,
            'cvar95_1d_pct' => $tail ? -array_sum($tail) / count($tail) * 100 : null,
            'risk_free_pct' => $this->riskFreeAnnual * 100,
        ];

        // Benchmark on the same dates (forward-filled).
        $bench = null;
        if ($benchmark) {
            ksort($benchmark);
            $aligned = [];
            $lastB = null;
            $bDates = array_keys($benchmark);
            $j = 0;
            foreach ($dateList as $d) {
                while ($j < count($bDates) && $bDates[$j] <= $d) {
                    $lastB = $benchmark[$bDates[$j++]];
                }
                $aligned[$d] = $lastB;
            }
            $aligned = array_filter($aligned, static fn ($v) => $v !== null);
            if (count($aligned) > 30) {
                $first = reset($aligned);
                $bench = array_map(static fn ($v) => $v / $first * 100, $aligned);
                $bRets = Stats::returns(array_values($aligned));
                $pRets = array_slice($rets, -count($bRets));
                $var = Stats::std($bRets) ** 2;
                if ($var > 0 && count($bRets) > 20) {
                    $m['beta'] = Stats::covariance($pRets, $bRets) / $var;
                    $m['correlation_index'] = Stats::correlation($pRets, $bRets);
                }
                $m['benchmark_return_period_pct'] = (end($aligned) / $first - 1) * 100;
                $m['excess_vs_benchmark_pct'] = $m['return_period_pct'] - $m['benchmark_return_period_pct'];
            }
        }

        // Risk contributions: w_i (Σw)_i / wΣw.
        $lastRow = end($frame);
        $sum = array_sum($lastRow);
        $w = array_map(static fn ($v) => $v / $sum, $lastRow);
        $cov = [];
        foreach ($symbols as $a) {
            foreach ($symbols as $b) {
                $cov[$a][$b] = Stats::covariance($assetRets[$a], $assetRets[$b]) ?? 0.0;
            }
        }
        $portVar = 0.0;
        $marginal = [];
        foreach ($symbols as $a) {
            $marginal[$a] = 0.0;
            foreach ($symbols as $b) {
                $marginal[$a] += $cov[$a][$b] * $w[$b];
            }
            $portVar += $w[$a] * $marginal[$a];
        }
        $contribution = [];
        foreach ($symbols as $a) {
            $contribution[$a] = $portVar > 0 ? $w[$a] * $marginal[$a] / $portVar * 100 : 0.0;
        }
        $weightedVol = 0.0;
        $correlation = [];
        $perSymbol = [];
        foreach ($symbols as $a) {
            $vol = Stats::std($assetRets[$a]) ?? 0.0;
            $weightedVol += $w[$a] * $vol;
            foreach ($symbols as $b) {
                $correlation[$a][$b] = $a === $b ? 1.0 : Stats::correlation($assetRets[$a], $assetRets[$b]);
            }
            $col = array_column(array_values($frame), $a);
            $perSymbol[$a] = [
                'return_period_pct' => (end($col) / $col[0] - 1) * 100,
                'volatility_annual_pct' => $vol * sqrt(self::TRADING_DAYS) * 100,
            ];
        }
        $m['diversification_ratio'] = $portVar > 0 ? $weightedVol / sqrt($portVar) : null;

        return [
            'days' => count($rets),
            'equity' => $equity,
            'benchmark' => $bench,
            'drawdown' => $drawdown,
            'metrics' => $m,
            'risk_contribution' => $contribution,
            'covariance' => $cov,
            'correlation' => $correlation,
            'per_symbol' => $perSymbol,
        ];
    }

    /**
     * Equal-risk-contribution weights: each holding adds the same share of portfolio variance.
     * A neutral reference for sizing that needs no return forecasts: a volatile or highly
     * correlated holding gets less money. Cyclical coordinate descent on x_i·(Σx)_i = 1/n
     * (Griveau-Billion, Richard & Roncalli, 2013), then normalised to sum to one.
     *
     * @param array<string, array<string, float>> $cov
     * @return array<string, float>|null weights summing to 1, or null if it doesn't converge
     */
    public static function riskParity(array $cov, int $maxIter = 500): ?array
    {
        $keys = array_keys($cov);
        $n = count($keys);
        if ($n === 0) {
            return null;
        }
        foreach ($keys as $k) {
            if (($cov[$k][$k] ?? 0) <= 0) {
                return null;   // a holding without price moves (or data) has no risk to balance
            }
        }
        $x = array_fill_keys($keys, 1 / $n);
        for ($it = 0; $it < $maxIter; $it++) {
            $change = 0.0;
            foreach ($keys as $i) {
                $a = $cov[$i][$i];
                $b = 0.0;
                foreach ($keys as $j) {
                    if ($j !== $i) {
                        $b += $cov[$i][$j] * $x[$j];
                    }
                }
                $new = (-$b + sqrt($b * $b + 4 * $a / $n)) / (2 * $a);
                $change = max($change, abs($new - $x[$i]));
                $x[$i] = $new;
            }
            if ($change < 1e-10) {
                $sum = array_sum($x);
                return array_map(static fn ($v) => $v / $sum, $x);
            }
        }
        return null;
    }

    /** @param list<float> $weightsPct @return array{hhi: float, effective_positions: float} */
    public static function concentration(array $weightsPct): array
    {
        // Shares of the invested part (weights may be of NAV, which also holds cash).
        $total = array_sum(array_filter($weightsPct, static fn ($w) => $w > 0));
        $hhi = 0.0;
        foreach ($weightsPct as $w) {
            if ($w > 0) {
                $hhi += ($w / $total) ** 2;
            }
        }
        return ['hhi' => $hhi, 'effective_positions' => $hhi ? 1 / $hhi : 0.0];
    }

    /** @param list<float> $dailyValues @return array{avg_daily_value: ?float, days_to_exit: ?float} */
    public static function liquidity(array $dailyValues, float $positionValue, int $days = 20): array
    {
        $avg = Stats::mean(array_slice($dailyValues, -$days));
        $capacity = $avg ? $avg * self::LIQUIDITY_PARTICIPATION : null;
        return ['avg_daily_value' => $avg, 'days_to_exit' => $capacity ? $positionValue / $capacity : null];
    }
}
