<?php

declare(strict_types=1);

namespace Sabadgardan\Analysis;

/**
 * Technical indicators on float lists. Outputs are aligned with the input; positions without
 * enough history are null. Semantics match pandas (rolling / ewm(adjust=False, min_periods)).
 */
final class Indicators
{
    /** @param list<float> $x @return list<?float> */
    public static function sma(array $x, int $window): array
    {
        $out = [];
        $sum = 0.0;
        foreach ($x as $i => $v) {
            $sum += $v;
            if ($i >= $window) {
                $sum -= $x[$i - $window];
            }
            $out[] = $i >= $window - 1 ? $sum / $window : null;
        }
        return $out;
    }

    /**
     * Recursive exponential average over the non-null values: y = a*x + (1-a)*y_prev, seeded with
     * the first value; output starts once ``$minPeriods`` values have been seen.
     *
     * @param list<?float> $x
     * @return list<?float>
     */
    public static function ewm(array $x, float $alpha, int $minPeriods): array
    {
        $out = [];
        $y = null;
        $seen = 0;
        foreach ($x as $v) {
            if ($v === null) {
                $out[] = null;
                continue;
            }
            $y = $y === null ? $v : $alpha * $v + (1 - $alpha) * $y;
            $seen++;
            $out[] = $seen >= $minPeriods ? $y : null;
        }
        return $out;
    }

    /** @param list<?float> $x @return list<?float> */
    public static function ema(array $x, int $span): array
    {
        return self::ewm($x, 2 / ($span + 1), $span);
    }

    /** Wilder's RSI. @param list<float> $x @return list<?float> */
    public static function rsi(array $x, int $window = 14): array
    {
        $gains = [null];
        $losses = [null];
        for ($i = 1, $n = count($x); $i < $n; $i++) {
            $d = $x[$i] - $x[$i - 1];
            $gains[] = max($d, 0.0);
            $losses[] = max(-$d, 0.0);
        }
        $g = self::ewm($gains, 1 / $window, $window);
        $l = self::ewm($losses, 1 / $window, $window);
        $out = [];
        foreach ($g as $i => $gv) {
            if ($gv === null || $l[$i] === null) {
                $out[] = null;
            } elseif ($l[$i] == 0) {
                $out[] = 100.0;  // no losses in the window
            } else {
                $out[] = 100 - 100 / (1 + $gv / $l[$i]);
            }
        }
        return $out;
    }

    /** @param list<float> $x @return array{macd: list<?float>, signal: list<?float>, hist: list<?float>} */
    public static function macd(array $x, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        $f = self::ema($x, $fast);
        $s = self::ema($x, $slow);
        $line = array_map(static fn ($a, $b) => $a === null || $b === null ? null : $a - $b, $f, $s);
        $sig = self::ewm($line, 2 / ($signal + 1), $signal);
        $hist = array_map(static fn ($a, $b) => $a === null || $b === null ? null : $a - $b, $line, $sig);
        return ['macd' => $line, 'signal' => $sig, 'hist' => $hist];
    }

    /** @param list<float> $x @return array{mid: list<?float>, upper: list<?float>, lower: list<?float>} */
    public static function bollinger(array $x, int $window = 20, float $k = 2.0): array
    {
        $mid = self::sma($x, $window);
        $upper = $lower = [];
        foreach ($x as $i => $_) {
            if ($mid[$i] === null) {
                $upper[] = $lower[] = null;
                continue;
            }
            $sd = Stats::std(array_slice($x, $i - $window + 1, $window), 0);
            $upper[] = $mid[$i] + $k * $sd;
            $lower[] = $mid[$i] - $k * $sd;
        }
        return ['mid' => $mid, 'upper' => $upper, 'lower' => $lower];
    }

    /**
     * Average True Range (Wilder).
     *
     * @param list<float> $high @param list<float> $low @param list<float> $close
     * @return list<?float>
     */
    public static function atr(array $high, array $low, array $close, int $window = 14): array
    {
        $tr = [];
        foreach ($close as $i => $_) {
            $range = $high[$i] - $low[$i];
            if ($i > 0) {
                $range = max($range, abs($high[$i] - $close[$i - 1]), abs($low[$i] - $close[$i - 1]));
            }
            $tr[] = $range;
        }
        return self::ewm($tr, 1 / $window, $window);
    }
}
