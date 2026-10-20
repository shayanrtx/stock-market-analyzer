<?php

declare(strict_types=1);

namespace Sabadgardan\Analysis;

/** Descriptive statistics on float lists. Nulls are skipped where noted. */
final class Stats
{
    /** @param list<?float> $x */
    public static function clean(array $x): array
    {
        return array_values(array_filter($x, static fn ($v) => $v !== null && is_finite($v)));
    }

    /** @param list<?float> $x */
    public static function mean(array $x): ?float
    {
        $x = self::clean($x);
        return $x ? array_sum($x) / count($x) : null;
    }

    /** Standard deviation; ``$ddof = 1`` (sample) like pandas, 0 for population. */
    public static function std(array $x, int $ddof = 1): ?float
    {
        $x = self::clean($x);
        $n = count($x);
        if ($n - $ddof <= 0) {
            return null;
        }
        $m = array_sum($x) / $n;
        $ss = 0.0;
        foreach ($x as $v) {
            $ss += ($v - $m) ** 2;
        }
        return sqrt($ss / ($n - $ddof));
    }

    /** Quantile with linear interpolation (numpy/pandas default). ``$q`` in 0..1. */
    public static function quantile(array $x, float $q): ?float
    {
        $x = self::clean($x);
        if (!$x) {
            return null;
        }
        sort($x);
        $pos = ($count = count($x)) > 1 ? $q * ($count - 1) : 0.0;
        $lo = (int) floor($pos);
        $hi = (int) ceil($pos);
        return $x[$lo] + ($x[$hi] - $x[$lo]) * ($pos - $lo);
    }

    public static function median(array $x): ?float
    {
        return self::quantile($x, 0.5);
    }

    /**
     * Simple returns between consecutive values.
     *
     * @param list<float> $x
     * @return list<float>
     */
    public static function returns(array $x): array
    {
        $out = [];
        for ($i = 1, $n = count($x); $i < $n; $i++) {
            if ($x[$i - 1] != 0) {
                $out[] = $x[$i] / $x[$i - 1] - 1;
            }
        }
        return $out;
    }

    /** Sample covariance of two equally long lists. */
    public static function covariance(array $a, array $b): ?float
    {
        $n = min(count($a), count($b));
        if ($n < 2) {
            return null;
        }
        $ma = array_sum(array_slice($a, 0, $n)) / $n;
        $mb = array_sum(array_slice($b, 0, $n)) / $n;
        $s = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $s += ($a[$i] - $ma) * ($b[$i] - $mb);
        }
        return $s / ($n - 1);
    }

    public static function correlation(array $a, array $b): ?float
    {
        $cov = self::covariance($a, $b);
        $sa = self::std($a);
        $sb = self::std($b);
        return $cov !== null && $sa && $sb ? $cov / ($sa * $sb) : null;
    }

    /** Percent change between the last value and the value ``$periods`` sessions earlier. */
    public static function pctReturn(array $x, int $periods): ?float
    {
        $n = count($x);
        if ($periods <= 0 || $n <= $periods || $x[$n - 1 - $periods] == 0) {
            return null;
        }
        return ($x[$n - 1] / $x[$n - 1 - $periods] - 1) * 100;
    }

    public static function clip(float $x, float $lo = -1.0, float $hi = 1.0): float
    {
        return max($lo, min($hi, $x));
    }

    /** Last non-null value of a series. */
    public static function last(array $x): ?float
    {
        for ($i = count($x) - 1; $i >= 0; $i--) {
            if ($x[$i] !== null && is_finite($x[$i])) {
                return (float) $x[$i];
            }
        }
        return null;
    }
}
