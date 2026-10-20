<?php

declare(strict_types=1);

namespace Sabadgardan\Analysis;

/**
 * How a symbol moves with an outside driver (the free-market dollar): beta and correlation of
 * weekly returns over the last year. Weekly, because the dollar trades every day while the
 * exchange is closed two to three days a week and has daily price limits; daily returns would
 * understate the link.
 */
final class Sensitivity
{
    /**
     * @param list<string> $dates symbol session dates (Y-m-d), oldest first
     * @param list<float> $close adjusted closes
     * @param array<string, float> $driver date => value, any calendar
     * @return array{beta: float, correlation: float, weeks: int, driver_return_pct: float, symbol_return_pct: float}|null
     */
    public static function versus(array $dates, array $close, array $driver, int $days = 365): ?array
    {
        if (count($dates) < 60 || count($driver) < 60) {
            return null;
        }
        ksort($driver);
        $end = end($dates);
        $start = date('Y-m-d', strtotime($end) - $days * 86400);
        // Last value of each ISO week, for both series.
        $weekly = static function (iterable $pairs) use ($start): array {
            $out = [];
            foreach ($pairs as $d => $v) {
                if ($d >= $start && $v > 0) {
                    $out[date('o-W', strtotime($d))] = $v;
                }
            }
            return $out;
        };
        $a = $weekly(array_combine($dates, $close));
        $b = $weekly($driver);
        $weeks = array_values(array_intersect(array_keys($a), array_keys($b)));
        if (count($weeks) < 20) {
            return null;
        }
        $ra = $rb = [];
        for ($i = 1; $i < count($weeks); $i++) {
            $ra[] = $a[$weeks[$i]] / $a[$weeks[$i - 1]] - 1;
            $rb[] = $b[$weeks[$i]] / $b[$weeks[$i - 1]] - 1;
        }
        $var = Stats::std($rb) ** 2;
        if ($var <= 0) {
            return null;
        }
        return [
            'beta' => Stats::covariance($ra, $rb) / $var,
            'correlation' => (float) Stats::correlation($ra, $rb),
            'weeks' => count($ra),
            'driver_return_pct' => ($b[end($weeks)] / $b[$weeks[0]] - 1) * 100,
            'symbol_return_pct' => ($a[end($weeks)] / $a[$weeks[0]] - 1) * 100,
        ];
    }

    /** Plain-language reading of a beta to the dollar. */
    public static function describe(?array $s): string
    {
        if (!$s) {
            return 'داده‌ی کافی برای سنجش رابطه با دلار نیست.';
        }
        $b = $s['beta'];
        $c = $s['correlation'];
        $strength = abs($c) >= 0.5 ? 'قوی' : (abs($c) >= 0.25 ? 'متوسط' : 'ضعیف');
        if (abs($c) < 0.15) {
            return 'حرکت این نماد عملاً مستقل از دلار آزاد بوده است.';
        }
        return sprintf('رابطه‌ی %s و %s با دلار: به ازای هر ۱۰٪ رشد دلار، این نماد به‌طور میانگین حدود %s٪ %s کرده است.',
            $b > 0 ? 'هم‌جهت' : 'خلاف‌جهت', $strength, number_format(abs($b) * 10, 1), $b > 0 ? 'رشد' : 'افت');
    }
}
