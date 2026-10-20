<?php

declare(strict_types=1);

namespace Sabadgardan\Analysis\Potential;

use Sabadgardan\Analysis\Indicators;
use Sabadgardan\Analysis\Stats;
use Sabadgardan\Market\Jalali;
use Sabadgardan\Market\PriceHistory;

/**
 * Retrospective statistics of one symbol on adjusted prices:
 * - record(): CAGR against inflation, Jalali-year returns, drawdowns, ranges
 * - analogs(): past days whose technical state resembles today, and what followed
 * - tradeOdds(): for a concrete plan (stop/target), how often similar past setups
 *   reached the target before the stop
 */
final class HistoricalStudy
{
    public const TRADING_DAYS = 250;
    public const HORIZON = 63;          // ~3 months of sessions
    public const MIN_SAMPLES = 6;
    public const SPACING = 10;          // sessions between samples, so one episode isn't counted many times

    /** @var list<?float> */ private array $sma200;
    /** @var list<?float> */ private array $rsi;
    /** @var list<?float> */ private array $drawdown;

    public function __construct(private PriceHistory $h, private float $inflationAnnual = 0.35)
    {
        $c = $h->close;
        $this->sma200 = Indicators::sma($c, 200);
        $this->rsi = Indicators::rsi($c, 14);
        $this->drawdown = self::rollingDrawdown($c, self::TRADING_DAYS, 60);
    }

    public function hurdle(int $days): float
    {
        return ((1 + $this->inflationAnnual) ** ($days / self::TRADING_DAYS) - 1) * 100;
    }

    /** @return array<string, mixed> */
    public function record(): array
    {
        $c = $this->h->close;
        $d = $this->h->dates;
        $n = count($c);
        $years = (strtotime($d[$n - 1]) - strtotime($d[0])) / 86400 / 365.25;
        $total = $c[$n - 1] / $c[0] - 1;
        $cagr = $years >= 1 ? (1 + $total) ** (1 / $years) - 1 : null;

        $peak = 0.0;
        $maxDd = 0.0;
        foreach ($c as $v) {
            $peak = max($peak, $v);
            $maxDd = min($maxDd, $v / $peak - 1);
        }

        // Returns per Jalali year: year-end close over previous year-end (first year: over first close).
        $yearEnd = [];
        $sessions = [];
        foreach ($d as $i => $date) {
            $y = Jalali::year($date);
            $yearEnd[$y] = $c[$i];
            $sessions[$y] = ($sessions[$y] ?? 0) + 1;
        }
        $annual = [];
        $prev = $c[0];
        foreach ($yearEnd as $y => $end) {
            $annual[$y] = ($end / $prev - 1) * 100;
            $prev = $end;
        }
        $firstYear = array_key_first($annual);
        if (count($annual) > 1 && $sessions[$firstYear] < 40) {
            unset($annual[$firstYear]);  // a few weeks of a first year isn't an annual return
        }

        $lastYear = array_slice($c, -self::TRADING_DAYS);
        $rets = array_slice(Stats::returns($c), -self::TRADING_DAYS);
        return [
            'first_date' => Jalali::fromGregorianString($d[0]),
            'years' => $years,
            'total_return_pct' => $total * 100,
            'cagr_pct' => $cagr !== null ? $cagr * 100 : null,
            'inflation_pct' => $this->inflationAnnual * 100,
            'beats_inflation' => $cagr !== null ? $cagr > $this->inflationAnnual : null,
            'annual_returns' => $annual,
            'max_drawdown_pct' => $maxDd * 100,
            'drawdown_from_ath_pct' => ($c[$n - 1] / max($c) - 1) * 100,
            'ath' => max($c),
            'high_52w' => max($lastYear),
            'low_52w' => min($lastYear),
            'volatility_annual_pct' => count($rets) > 20 ? Stats::std($rets) * sqrt(self::TRADING_DAYS) * 100 : null,
        ];
    }

    /**
     * Indices of past sessions in the same state as the latest one:
     * same side of SMA200, RSI within ±10, distance from the 1-year high within ±10 points.
     *
     * @return list<int>|null null when there is not enough history to judge
     */
    public function similarDays(int $horizon = self::HORIZON): ?array
    {
        $c = $this->h->close;
        $now = count($c) - 1;
        if ($now + 1 < 200 + $horizon + self::MIN_SAMPLES
            || $this->sma200[$now] === null || $this->rsi[$now] === null || $this->drawdown[$now] === null) {
            return null;
        }
        $aboveNow = $c[$now] > $this->sma200[$now];
        $days = [];
        $lastTaken = -self::SPACING;
        for ($i = 200; $i <= $now - $horizon; $i++) {
            if ($i - $lastTaken < self::SPACING || $this->rsi[$i] === null || $this->drawdown[$i] === null
                || $this->sma200[$i] === null) {
                continue;
            }
            if (($c[$i] > $this->sma200[$i]) === $aboveNow
                && abs($this->rsi[$i] - $this->rsi[$now]) <= 10
                && abs($this->drawdown[$i] - $this->drawdown[$now]) <= 10) {
                $days[] = $i;
                $lastTaken = $i;
            }
        }
        return $days;
    }

    /** @return array<string, mixed>|null */
    public function analogs(int $horizon = self::HORIZON): ?array
    {
        $days = $this->similarDays($horizon);
        if ($days === null) {
            return null;
        }
        if (count($days) < self::MIN_SAMPLES) {
            return ['samples' => count($days)];
        }
        $c = $this->h->close;
        $fwd = array_map(static fn ($i) => ($c[$i + $horizon] / $c[$i] - 1) * 100, $days);
        $hurdle = $this->hurdle($horizon);
        $n = count($fwd);
        return [
            'samples' => $n,
            'horizon_days' => $horizon,
            'mean_pct' => array_sum($fwd) / $n,
            'median_pct' => Stats::median($fwd),
            'p20_pct' => Stats::quantile($fwd, 0.2),
            'p80_pct' => Stats::quantile($fwd, 0.8),
            'win_rate' => count(array_filter($fwd, static fn ($x) => $x > 0)) / $n,
            'beat_inflation_rate' => count(array_filter($fwd, static fn ($x) => $x > $hurdle)) / $n,
            'hurdle_pct' => $hurdle,
            'best_pct' => max($fwd),
            'worst_pct' => min($fwd),
            'dates' => array_map(fn ($i) => Jalali::fromGregorianString($this->h->dates[$i]), array_slice($days, -5)),
            'forward' => $fwd,
        ];
    }

    /**
     * Odds of a trade plan: from each comparable past session, did price reach +target before -stop
     * within the horizon? Uses daily highs/lows; a day touching both counts as a loss (conservative).
     * Falls back to all sessions when too few similar ones exist (``conditioned`` = false).
     *
     * @param float $targetPct e.g. 12.0 for +12%
     * @param float $stopPct   e.g. 6.0 for -6%
     * @param int   $side      +1 long (target above), -1 short/exit view (target below)
     * @return array<string, mixed>|null
     */
    public function tradeOdds(float $targetPct, float $stopPct, int $horizon = 42, int $side = 1): ?array
    {
        $h = $this->h;
        $n = $h->count();
        $days = $this->similarDays($horizon) ?? [];
        $conditioned = count($days) >= self::MIN_SAMPLES * 2;
        if (!$conditioned) {
            $days = [];
            for ($i = max(20, $n - 1 - 750); $i <= $n - 1 - $horizon; $i += 5) {
                $days[] = $i;
            }
        }
        if (count($days) < self::MIN_SAMPLES) {
            return null;
        }
        $wins = $losses = 0;
        $timeoutReturns = [];
        $daysToTarget = [];
        foreach ($days as $i) {
            $entry = $h->close[$i];
            $up = $entry * (1 + $side * $targetPct / 100);
            $down = $entry * (1 - $side * $stopPct / 100);
            $outcome = null;
            for ($j = $i + 1; $j <= $i + $horizon; $j++) {
                $hitStop = $side > 0 ? $h->low[$j] <= $down : $h->high[$j] >= $down;
                $hitTarget = $side > 0 ? $h->high[$j] >= $up : $h->low[$j] <= $up;
                if ($hitStop) {
                    $outcome = 'loss';
                    break;
                }
                if ($hitTarget) {
                    $outcome = 'win';
                    $daysToTarget[] = $j - $i;
                    break;
                }
            }
            if ($outcome === 'win') {
                $wins++;
            } elseif ($outcome === 'loss') {
                $losses++;
            } else {
                $timeoutReturns[] = $side * ($h->close[$i + $horizon] / $entry - 1) * 100;
            }
        }
        $total = count($days);
        $timeouts = count($timeoutReturns);
        $expectancy = ($wins * $targetPct - $losses * $stopPct + array_sum($timeoutReturns)) / $total;
        return [
            'samples' => $total,
            'conditioned' => $conditioned,
            'horizon_days' => $horizon,
            'win_rate' => $wins / $total,
            'loss_rate' => $losses / $total,
            'timeout_rate' => $timeouts / $total,
            'avg_days_to_target' => $daysToTarget ? array_sum($daysToTarget) / count($daysToTarget) : null,
            'expectancy_pct' => $expectancy,
        ];
    }

    /**
     * Percent distance from the rolling maximum over ``$window`` sessions (<= 0).
     *
     * @param list<float> $c
     * @return list<?float>
     */
    private static function rollingDrawdown(array $c, int $window, int $minPeriods): array
    {
        $out = [];
        $deque = [];  // indices with decreasing values: front is the window max
        foreach ($c as $i => $v) {
            while ($deque && $c[end($deque)] <= $v) {
                array_pop($deque);
            }
            $deque[] = $i;
            if ($deque[0] <= $i - $window) {
                array_shift($deque);
            }
            $out[] = $i + 1 >= $minPeriods ? ($v / $c[$deque[0]] - 1) * 100 : null;
        }
        return $out;
    }
}
