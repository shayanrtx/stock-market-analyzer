<?php

declare(strict_types=1);

namespace Sabadgardan\View;

use Sabadgardan\Analysis\Indicators;
use Sabadgardan\Analysis\Potential\HistoricalStudy;
use Sabadgardan\Analysis\Potential\PotentialResult;
use Sabadgardan\Market\Jalali;
use Sabadgardan\Market\PriceHistory;

/**
 * ECharts option builders. Functions can't travel through JSON, so string values "fn:<name>" are
 * swapped for formatter functions by assets/app.js. Iranian convention: green up, red down.
 */
final class Charts
{
    public const UP = '#059669';
    public const DOWN = '#dc2626';
    public const ACCENT = '#2563eb';
    public const MUTED = '#9ca3af';
    public const GRID = '#eef0f3';
    public const SERIES = ['#2563eb', '#f59e0b', '#8b5cf6', '#10b981', '#ef4444', '#06b6d4', '#ec4899', '#84cc16',
                           '#6366f1', '#f97316', '#14b8a6', '#a855f7'];
    private const FONT = 'Vazirmatn, Tahoma, sans-serif';

    private static function base(array $extra): array
    {
        return $extra + [
            'textStyle' => ['fontFamily' => self::FONT, 'color' => '#374151'],
            'color' => self::SERIES,
            'animationDuration' => 400,
            'tooltip' => ['trigger' => 'axis', 'confine' => true, 'textStyle' => ['fontFamily' => self::FONT]],
        ];
    }

    private static function axis(?array $data = null, array $extra = []): array
    {
        $ax = ['axisLine' => ['lineStyle' => ['color' => '#d1d5db']], 'axisLabel' => ['color' => '#6b7280'],
               'splitLine' => ['lineStyle' => ['color' => self::GRID]]];
        if ($data !== null) {
            $ax['type'] = 'category';
            $ax['data'] = $data;
        }
        return array_replace_recursive($ax, $extra);
    }

    private static function r(?float $v, int $d = 2): ?float
    {
        return $v === null || !is_finite($v) ? null : round($v, $d);
    }

    /** @param list<string> $dates */
    private static function jdates(array $dates): array
    {
        return array_map([Jalali::class, 'fromGregorianString'], $dates);
    }

    // ---------------------------------------------------------------- stock

    public static function pricePanels(PriceHistory $h, int $showDays = 250): array
    {
        $c = $h->close;
        $n = count($c);
        $dates = self::jdates($h->dates);
        $bb = Indicators::bollinger($c);
        $m = Indicators::macd($c);
        $rsi = Indicators::rsi($c);
        $start = max(0, 100 - $showDays / max($n, 1) * 100);
        $line = static fn (string $name, array $s, string $color, float $w = 1.2, int $idx = 0, bool $dashed = false) => [
            'name' => $name, 'type' => 'line', 'data' => array_map(static fn ($v) => self::r($v), $s),
            'xAxisIndex' => $idx, 'yAxisIndex' => $idx, 'showSymbol' => false,
            'lineStyle' => ['width' => $w, 'color' => $color, 'type' => $dashed ? 'dashed' : 'solid'],
            'itemStyle' => ['color' => $color]];
        $ohlc = [];
        $vol = [];
        for ($i = 0; $i < $n; $i++) {
            $ohlc[] = [self::r($h->open[$i], 0), self::r($c[$i], 0), self::r($h->low[$i], 0), self::r($h->high[$i], 0)];
            $vol[] = ['value' => self::r($h->volume[$i], 0),
                      'itemStyle' => ['color' => $c[$i] >= $h->open[$i] ? self::UP : self::DOWN, 'opacity' => 0.55]];
        }
        $grids = [['left' => 56, 'right' => 16, 'top' => 36, 'height' => '48%'],
                  ['left' => 56, 'right' => 16, 'top' => '60%', 'height' => '9%'],
                  ['left' => 56, 'right' => 16, 'top' => '72%', 'height' => '9%'],
                  ['left' => 56, 'right' => 16, 'top' => '84%', 'height' => '9%']];
        $x = [];
        for ($i = 0; $i < 4; $i++) {
            $x[] = self::axis($dates, ['gridIndex' => $i, 'boundaryGap' => true, 'axisTick' => ['show' => false],
                                       'axisLabel' => ['show' => $i === 3]]);
        }
        return self::base([
            'legend' => ['top' => 0, 'data' => ['قیمت', 'SMA20', 'SMA50', 'SMA200', 'بولینگر'], 'itemWidth' => 14,
                         'textStyle' => ['fontSize' => 11]],
            'axisPointer' => ['link' => [['xAxisIndex' => 'all']], 'label' => ['backgroundColor' => '#6b7280']],
            'tooltip' => ['trigger' => 'axis', 'axisPointer' => ['type' => 'cross'], 'confine' => true,
                          'textStyle' => ['fontFamily' => self::FONT, 'fontSize' => 12]],
            'grid' => $grids,
            'xAxis' => $x,
            'yAxis' => [
                self::axis(null, ['scale' => true, 'gridIndex' => 0, 'axisLabel' => ['formatter' => 'fn:compact']]),
                self::axis(null, ['scale' => true, 'gridIndex' => 1, 'axisLabel' => ['show' => false], 'splitLine' => ['show' => false]]),
                self::axis(null, ['gridIndex' => 2, 'min' => 0, 'max' => 100, 'interval' => 50,
                                  'axisLabel' => ['fontSize' => 10, 'color' => '#9ca3af']]),
                self::axis(null, ['scale' => true, 'gridIndex' => 3, 'axisLabel' => ['show' => false], 'splitLine' => ['show' => false]]),
            ],
            'series' => [
                ['name' => 'قیمت', 'type' => 'candlestick', 'data' => $ohlc,
                 'itemStyle' => ['color' => self::UP, 'color0' => self::DOWN, 'borderColor' => self::UP, 'borderColor0' => self::DOWN]],
                $line('SMA20', Indicators::sma($c, 20), '#f59e0b'),
                $line('SMA50', Indicators::sma($c, 50), '#2563eb'),
                $line('SMA200', Indicators::sma($c, 200), '#8b5cf6', 1.6),
                $line('بولینگر', $bb['upper'], '#cbd5e1', 1, 0, true),
                $line('بولینگر', $bb['lower'], '#cbd5e1', 1, 0, true),
                ['name' => 'حجم', 'type' => 'bar', 'xAxisIndex' => 1, 'yAxisIndex' => 1, 'data' => $vol],
                $line('RSI14', $rsi, '#7c3aed', 1.2, 2) + ['markLine' => ['silent' => true, 'symbol' => 'none',
                    'lineStyle' => ['color' => '#e5e7eb', 'type' => 'dashed'], 'label' => ['show' => false],
                    'data' => [['yAxis' => 30], ['yAxis' => 70]]]],
                ['name' => 'MACD', 'type' => 'bar', 'xAxisIndex' => 3, 'yAxisIndex' => 3,
                 'data' => array_map(static fn ($v) => ['value' => self::r($v),
                     'itemStyle' => ['color' => ($v ?? 0) >= 0 ? self::UP : self::DOWN]], $m['hist'])],
                $line('MACD خط', $m['macd'], '#2563eb', 1, 3),
                $line('سیگنال', $m['signal'], '#f59e0b', 1, 3),
            ],
            'dataZoom' => [
                ['type' => 'inside', 'xAxisIndex' => [0, 1, 2, 3], 'start' => $start, 'end' => 100],
                ['type' => 'slider', 'xAxisIndex' => [0, 1, 2, 3], 'bottom' => 0, 'height' => 14, 'start' => $start,
                 'end' => 100, 'borderColor' => 'transparent', 'fillerColor' => 'rgba(37,99,235,.08)', 'showDetail' => false],
            ],
        ]);
    }

    public static function gauge(float $score): array
    {
        return self::base([
            'tooltip' => ['show' => false],
            'series' => [[
                'type' => 'gauge', 'startAngle' => 200, 'endAngle' => -20, 'min' => 0, 'max' => 100, 'radius' => '100%',
                'center' => ['50%', '62%'], 'splitNumber' => 4,
                'axisLine' => ['lineStyle' => ['width' => 14, 'color' => [[0.35, '#fca5a5'], [0.45, '#fcd34d'],
                                                                         [0.55, '#e5e7eb'], [0.65, '#86efac'], [1, self::UP]]]],
                'pointer' => ['length' => '58%', 'width' => 5, 'itemStyle' => ['color' => '#374151']],
                'axisTick' => ['show' => false], 'splitLine' => ['show' => false],
                'axisLabel' => ['distance' => -32, 'color' => '#9ca3af', 'fontSize' => 10],
                'anchor' => ['show' => true, 'size' => 10, 'itemStyle' => ['color' => '#374151']],
                'detail' => ['valueAnimation' => true, 'formatter' => '{value}', 'offsetCenter' => [0, '32%'],
                             'fontSize' => 30, 'fontWeight' => 700, 'color' => '#111827', 'fontFamily' => self::FONT],
                'data' => [['value' => round($score)]],
            ]],
        ]);
    }

    public static function factorContributions(PotentialResult $p): array
    {
        $f = $p->factors;
        usort($f, static fn ($a, $b) => $a->contribution <=> $b->contribution);
        $reach = max(array_map(static fn ($x) => abs($x->contribution), $f) ?: [1]) * 1.3 ?: 1;
        return self::base([
            'tooltip' => ['trigger' => 'axis', 'axisPointer' => ['type' => 'shadow'], 'formatter' => 'fn:points'],
            'grid' => ['left' => 8, 'right' => 160, 'top' => 8, 'bottom' => 24],
            'xAxis' => self::axis(null, ['type' => 'value', 'min' => -round($reach, 1), 'max' => round($reach, 1),
                                         'axisLabel' => ['formatter' => 'fn:signed']]),
            'yAxis' => self::axis(array_map(static fn ($x) => $x->name, $f), ['position' => 'right',
                                  'axisTick' => ['show' => false], 'axisLabel' => ['color' => '#374151', 'fontSize' => 12]]),
            'series' => [['type' => 'bar', 'barWidth' => '55%', 'name' => 'اثر روی امتیاز',
                          'data' => array_map(static fn ($x) => ['value' => round($x->contribution, 1),
                              'itemStyle' => ['color' => $x->contribution >= 0 ? self::UP : self::DOWN, 'borderRadius' => 3]], $f),
                          'label' => ['show' => true, 'position' => 'outside', 'formatter' => 'fn:signed', 'fontSize' => 11,
                                      'color' => '#6b7280']]],
        ]);
    }

    public static function scenarios(PriceHistory $h, PotentialResult $p, int $days = 126): array
    {
        $close = array_slice($h->close, -$days);
        $dates = self::jdates(array_slice($h->dates, -$days));
        $horizon = HistoricalStudy::HORIZON;
        $future = [];
        for ($k = 1; $k <= $horizon; $k++) {
            $future[] = $k % 21 === 0 ? '+' . intdiv($k, 21) . ' ماه' : "+$k جلسه";
        }
        $n = count($close);
        $last = end($close);
        $path = static function (float $target) use ($n, $last, $horizon): array {
            $out = array_fill(0, $n - 1, null);
            $out[] = round($last);
            for ($k = 1; $k <= $horizon; $k++) {
                $out[] = round($last + ($target - $last) * $k / $horizon);
            }
            return $out;
        };
        $series = [['name' => 'قیمت', 'type' => 'line', 'showSymbol' => false,
                    'data' => [...array_map(static fn ($v) => round($v), $close), ...array_fill(0, $horizon, null)],
                    'lineStyle' => ['color' => '#111827', 'width' => 1.6], 'itemStyle' => ['color' => '#111827']]];
        foreach ([['bull', 'خوش‌بینانه', self::UP], ['base', 'پایه', self::ACCENT], ['bear', 'بدبینانه', self::DOWN]] as [$k, $name, $col]) {
            if (isset($p->scenarios[$k])) {
                $series[] = ['name' => $name, 'type' => 'line', 'data' => $path((float) $p->scenarios[$k]), 'showSymbol' => false,
                             'lineStyle' => ['type' => 'dashed', 'color' => $col, 'width' => 1.6], 'itemStyle' => ['color' => $col],
                             'endLabel' => ['show' => true, 'formatter' => 'fn:endlabel', 'color' => $col, 'fontSize' => 11]];
            }
        }
        $marks = [];
        if ($p->levels['target']) {
            $marks[] = ['yAxis' => round($p->levels['target']), 'name' => 'مقاومت', 'lineStyle' => ['color' => self::UP]];
        }
        if ($p->levels['stop']) {
            $marks[] = ['yAxis' => round($p->levels['stop']), 'name' => 'حد ضرر', 'lineStyle' => ['color' => self::DOWN]];
        }
        if ($marks) {
            $series[0]['markLine'] = ['symbol' => 'none', 'silent' => true, 'data' => $marks,
                                      'lineStyle' => ['type' => 'dotted', 'width' => 1],
                                      'label' => ['formatter' => '{b}', 'position' => 'insideStartTop', 'fontSize' => 10]];
        }
        return self::base([
            'legend' => ['top' => 0, 'textStyle' => ['fontSize' => 11]],
            'grid' => ['left' => 56, 'right' => 96, 'top' => 32, 'bottom' => 28],
            'xAxis' => self::axis([...$dates, ...$future], ['boundaryGap' => false, 'axisLabel' => ['hideOverlap' => true]]),
            'yAxis' => self::axis(null, ['type' => 'value', 'scale' => true, 'axisLabel' => ['formatter' => 'fn:compact']]),
            'series' => $series,
        ]);
    }

    public static function analogHistogram(array $analogs): array
    {
        $fwd = $analogs['forward'] ?? [];
        if (!$fwd) {
            return [];
        }
        $lo = (int) floor(min($fwd) / 5) * 5;
        $hi = max((int) ceil(max($fwd) / 5) * 5, $lo + 5);
        $width = max(5, (int) ceil(($hi - $lo) / 12 / 5) * 5);
        $hurdle = $analogs['hurdle_pct'] ?? 0;
        $labels = $data = [];
        for ($a = $lo; $a < $hi; $a += $width) {
            $count = count(array_filter($fwd, static fn ($x) => $x >= $a && $x < $a + $width));
            $labels[] = "\u{200E}" . ($a > 0 ? '+' : '') . $a . '٪';
            $col = $a >= $hurdle ? self::UP : ($a >= 0 ? '#86efac' : self::DOWN);
            $data[] = ['value' => $count, 'itemStyle' => ['color' => $col, 'borderRadius' => [3, 3, 0, 0]]];
        }
        return self::base([
            'tooltip' => ['trigger' => 'axis', 'axisPointer' => ['type' => 'shadow']],
            'grid' => ['left' => 36, 'right' => 16, 'top' => 16, 'bottom' => 44],
            'xAxis' => self::axis($labels, ['name' => 'بازده ۳ ماه بعد (ابتدای بازه)', 'nameLocation' => 'middle', 'nameGap' => 28,
                                            'axisLabel' => ['fontSize' => 10, 'hideOverlap' => true]]),
            'yAxis' => self::axis(null, ['type' => 'value', 'minInterval' => 1]),
            'series' => [['type' => 'bar', 'name' => 'تعداد موقعیت', 'barCategoryGap' => '8%', 'data' => $data]],
        ]);
    }

    /** @param array<int|string, float> $values label => value (percent) */
    public static function bars(array $values, string $name, string $formatter = 'fn:pct0', bool $money = false): array
    {
        return self::base([
            'tooltip' => ['trigger' => 'axis', 'axisPointer' => ['type' => 'shadow'],
                          'valueFormatter' => $money ? 'fn:toman' : 'fn:pct'],
            'grid' => ['left' => 56, 'right' => 16, 'top' => 20, 'bottom' => 28],
            'xAxis' => self::axis(array_map('strval', array_keys($values)), ['axisTick' => ['show' => false]]),
            'yAxis' => self::axis(null, ['type' => 'value', 'axisLabel' => ['formatter' => $money ? 'fn:tomanAxis' : '{value}٪']]),
            'series' => [['type' => 'bar', 'name' => $name, 'barMaxWidth' => 36,
                          'data' => array_map(static fn ($v) => ['value' => self::r($v, 1),
                              'itemStyle' => ['color' => $v >= 0 ? self::UP : self::DOWN, 'borderRadius' => 3]], array_values($values)),
                          'label' => ['show' => count($values) <= 14, 'position' => 'top', 'formatter' => $formatter,
                                      'fontSize' => 10, 'color' => '#6b7280']]],
        ]);
    }

    /** @param list<float> $close @param list<string> $dates */
    public static function underwater(array $dates, array $close): array
    {
        $peak = 0.0;
        $dd = [];
        foreach ($close as $v) {
            $peak = max($peak, $v);
            $dd[] = round(($v / $peak - 1) * 100, 1);
        }
        return self::base([
            'tooltip' => ['trigger' => 'axis', 'valueFormatter' => 'fn:pct'],
            'grid' => ['left' => 48, 'right' => 16, 'top' => 12, 'bottom' => 28],
            'xAxis' => self::axis(self::jdates($dates), ['boundaryGap' => false, 'axisLabel' => ['hideOverlap' => true]]),
            'yAxis' => self::axis(null, ['type' => 'value', 'max' => 0, 'axisLabel' => ['formatter' => '{value}٪']]),
            'series' => [['type' => 'line', 'name' => 'افت از سقف', 'data' => $dd, 'showSymbol' => false,
                          'lineStyle' => ['width' => 1, 'color' => self::DOWN], 'areaStyle' => ['color' => 'rgba(220,38,38,.12)']]],
        ]);
    }

    /** @param list<array{date: string, buyer_power: ?float, net_volume: float}> $flow */
    public static function moneyFlow(array $flow, PriceHistory $raw): array
    {
        if (!$flow) {
            return [];
        }
        $priceByDate = array_combine($raw->dates, $raw->close);
        $last = $raw->last();
        $values = [];
        foreach ($flow as $row) {
            $price = $priceByDate[$row['date']] ?? $last;
            $values[] = round($row['net_volume'] * $price / 10_000_000, 1);  // million toman
        }
        return self::base([
            'legend' => ['top' => 0, 'textStyle' => ['fontSize' => 11]],
            'grid' => ['left' => 56, 'right' => 48, 'top' => 32, 'bottom' => 28],
            'xAxis' => self::axis(self::jdates(array_column($flow, 'date')), ['axisLabel' => ['hideOverlap' => true]]),
            'yAxis' => [self::axis(null, ['type' => 'value', 'name' => 'م.ت', 'axisLabel' => ['formatter' => 'fn:compact']]),
                        self::axis(null, ['type' => 'value', 'name' => 'قدرت', 'splitLine' => ['show' => false]])],
            'series' => [
                ['type' => 'bar', 'name' => 'ورود/خروج پول حقیقی (م.ت)', 'data' => array_map(static fn ($v) => ['value' => $v,
                    'itemStyle' => ['color' => $v >= 0 ? self::UP : self::DOWN]], $values)],
                ['type' => 'line', 'name' => 'قدرت خریدار', 'yAxisIndex' => 1, 'showSymbol' => false,
                 'data' => array_map(static fn ($r) => self::r($r['buyer_power']), $flow),
                 'lineStyle' => ['color' => '#7c3aed', 'width' => 1.4], 'itemStyle' => ['color' => '#7c3aed'],
                 'markLine' => ['silent' => true, 'symbol' => 'none', 'data' => [['yAxis' => 1]],
                                'lineStyle' => ['color' => '#c4b5fd', 'type' => 'dashed'], 'label' => ['show' => false]]],
            ],
        ]);
    }

    // ------------------------------------------------------------ portfolio

    /** @param array<string, array{value: float, cost: float, pnl: float}> $series */
    public static function performance(array $series): array
    {
        if (count($series) < 2) {
            return [];
        }
        $dates = self::jdates(array_keys($series));
        $col = static fn (string $k) => array_map(static fn ($r) => round($r[$k] / 10), array_values($series));  // toman
        return self::base([
            'legend' => ['top' => 0, 'textStyle' => ['fontSize' => 11]],
            'tooltip' => ['trigger' => 'axis', 'valueFormatter' => 'fn:toman', 'confine' => true],
            'axisPointer' => ['link' => [['xAxisIndex' => 'all']]],
            'grid' => [['left' => 64, 'right' => 16, 'top' => 32, 'height' => '52%'],
                       ['left' => 64, 'right' => 16, 'top' => '72%', 'height' => '20%']],
            'xAxis' => [self::axis($dates, ['boundaryGap' => false, 'axisLabel' => ['show' => false]]),
                        self::axis($dates, ['gridIndex' => 1, 'boundaryGap' => false, 'axisLabel' => ['hideOverlap' => true]])],
            'yAxis' => [self::axis(null, ['type' => 'value', 'scale' => true, 'axisLabel' => ['formatter' => 'fn:tomanAxis']]),
                        self::axis(null, ['type' => 'value', 'gridIndex' => 1, 'splitNumber' => 2,
                                          'axisLabel' => ['formatter' => 'fn:tomanAxis', 'fontSize' => 10]])],
            'series' => [
                ['name' => 'ارزش سهم‌ها', 'type' => 'line', 'data' => $col('value'), 'showSymbol' => false,
                 'lineStyle' => ['width' => 2, 'color' => self::ACCENT], 'itemStyle' => ['color' => self::ACCENT],
                 'areaStyle' => ['color' => 'rgba(37,99,235,.06)']],
                ['name' => 'بهای تمام‌شده', 'type' => 'line', 'step' => 'end', 'data' => $col('cost'), 'showSymbol' => false,
                 'lineStyle' => ['width' => 1.4, 'color' => self::MUTED], 'itemStyle' => ['color' => self::MUTED]],
                ['name' => 'سود و زیان تجمعی', 'type' => 'line', 'xAxisIndex' => 1, 'yAxisIndex' => 1, 'data' => $col('pnl'),
                 'showSymbol' => false, 'lineStyle' => ['width' => 1.4, 'color' => '#7c3aed'], 'itemStyle' => ['color' => '#7c3aed'],
                 'areaStyle' => ['color' => 'rgba(124,58,237,.10)']],
            ],
        ]);
    }

    /** @param array<string, float> $equity @param array<string, float>|null $bench @param array<string, float> $dd */
    public static function equityVsBenchmark(array $equity, ?array $bench, array $dd): array
    {
        $dates = self::jdates(array_keys($equity));
        $series = [['name' => 'سبد (ترکیب فعلی)', 'type' => 'line', 'showSymbol' => false,
                    'data' => array_map(static fn ($v) => round($v, 2), array_values($equity)),
                    'lineStyle' => ['width' => 2, 'color' => self::ACCENT], 'itemStyle' => ['color' => self::ACCENT],
                    'areaStyle' => ['color' => 'rgba(37,99,235,.06)']]];
        if ($bench) {
            $series[] = ['name' => 'شاخص کل', 'type' => 'line', 'showSymbol' => false,
                         'data' => array_map(static fn ($d) => isset($bench[$d]) ? round($bench[$d], 2) : null, array_keys($equity)),
                         'lineStyle' => ['width' => 1.4, 'color' => self::MUTED], 'itemStyle' => ['color' => self::MUTED]];
        }
        $series[] = ['name' => 'افت از سقف', 'type' => 'line', 'xAxisIndex' => 1, 'yAxisIndex' => 1, 'showSymbol' => false,
                     'data' => array_map(static fn ($v) => round($v, 1), array_values($dd)),
                     'lineStyle' => ['width' => 1, 'color' => self::DOWN], 'areaStyle' => ['color' => 'rgba(220,38,38,.12)'],
                     'itemStyle' => ['color' => self::DOWN]];
        return self::base([
            'legend' => ['top' => 0, 'textStyle' => ['fontSize' => 11]],
            'axisPointer' => ['link' => [['xAxisIndex' => 'all']]],
            'grid' => [['left' => 48, 'right' => 16, 'top' => 32, 'height' => '58%'],
                       ['left' => 48, 'right' => 16, 'top' => '76%', 'height' => '16%']],
            'xAxis' => [self::axis($dates, ['boundaryGap' => false, 'axisLabel' => ['show' => false]]),
                        self::axis($dates, ['gridIndex' => 1, 'boundaryGap' => false, 'axisLabel' => ['hideOverlap' => true]])],
            'yAxis' => [self::axis(null, ['type' => 'value', 'scale' => true]),
                        self::axis(null, ['type' => 'value', 'gridIndex' => 1, 'max' => 0, 'splitNumber' => 2,
                                          'axisLabel' => ['formatter' => '{value}٪', 'fontSize' => 10]])],
            'series' => $series,
        ]);
    }

    /** @param array<string, float> $items */
    public static function donut(array $items, int $top = 9): array
    {
        arsort($items);
        if (count($items) > $top + 1) {
            $rest = array_sum(array_slice($items, $top));
            $items = array_slice($items, 0, $top, true) + ['سایر' => $rest];
        }
        $data = [];
        foreach ($items as $k => $v) {
            $data[] = ['name' => (string) $k, 'value' => round($v, 2)];
        }
        return self::base([
            'tooltip' => ['trigger' => 'item', 'formatter' => '{b}: {d}٪'],
            'legend' => ['type' => 'scroll', 'orient' => 'vertical', 'right' => 0, 'top' => 'middle', 'textStyle' => ['fontSize' => 11]],
            'series' => [['type' => 'pie', 'radius' => ['48%', '70%'], 'center' => ['36%', '50%'], 'avoidLabelOverlap' => true,
                          'itemStyle' => ['borderColor' => '#fff', 'borderWidth' => 2], 'label' => ['show' => false], 'data' => $data]],
        ]);
    }

    /** Horizontal bars growing from the labels (right) in RTL. @param array<string, float> $items */
    public static function hbar(array $items, string $color = self::ACCENT, bool $signedColors = false, bool $money = false): array
    {
        $pairs = array_reverse($items, true);
        return self::base([
            'tooltip' => ['trigger' => 'axis', 'axisPointer' => ['type' => 'shadow'], 'valueFormatter' => $money ? 'fn:toman' : 'fn:pct'],
            'grid' => ['left' => 48, 'right' => 160, 'top' => 8, 'bottom' => 20],
            'xAxis' => self::axis(null, ['type' => 'value', 'inverse' => !$signedColors,
                                         'axisLabel' => ['formatter' => $money ? 'fn:tomanAxis' : '{value}٪']]),
            'yAxis' => self::axis(array_map('strval', array_keys($pairs)), ['position' => 'right', 'axisTick' => ['show' => false],
                                  'axisLabel' => ['color' => '#374151', 'width' => 150, 'overflow' => 'truncate']]),
            'series' => [['type' => 'bar', 'barWidth' => '55%',
                          'data' => array_map(static fn ($v) => ['value' => round($v, $money ? 0 : 1),
                              'itemStyle' => ['color' => $signedColors ? ($v >= 0 ? self::UP : self::DOWN) : $color,
                                              'borderRadius' => 3]], array_values($pairs)),
                          'label' => ['show' => true, 'position' => 'outside', 'formatter' => $money ? 'fn:tomanLabel' : 'fn:pct1',
                                      'fontSize' => 10, 'color' => '#6b7280']]],
        ]);
    }

    /** @param list<array{0: string, 1: float, 2: float}> $rows symbol, weight %, risk share % */
    public static function riskVsWeight(array $rows): array
    {
        usort($rows, static fn ($a, $b) => $a[2] <=> $b[2]);
        return self::base([
            'tooltip' => ['trigger' => 'axis', 'axisPointer' => ['type' => 'shadow'], 'valueFormatter' => 'fn:pct'],
            'legend' => ['top' => 0, 'textStyle' => ['fontSize' => 11]],
            'grid' => ['left' => 16, 'right' => 90, 'top' => 28, 'bottom' => 20],
            'xAxis' => self::axis(null, ['type' => 'value', 'inverse' => true, 'axisLabel' => ['formatter' => '{value}٪']]),
            'yAxis' => self::axis(array_column($rows, 0), ['position' => 'right', 'axisTick' => ['show' => false]]),
            'series' => [
                ['name' => 'وزن در سبد', 'type' => 'bar', 'data' => array_map(static fn ($r) => round($r[1], 1), $rows),
                 'itemStyle' => ['color' => '#cbd5e1', 'borderRadius' => 3]],
                ['name' => 'سهم از ریسک', 'type' => 'bar', 'data' => array_map(static fn ($r) => round($r[2], 1), $rows),
                 'itemStyle' => ['color' => '#f97316', 'borderRadius' => 3]],
            ],
        ]);
    }

    /** @param array<string, array<string, ?float>> $corr */
    public static function correlation(array $corr): array
    {
        $names = array_keys($corr);
        $data = [];
        foreach ($names as $i => $a) {
            foreach ($names as $j => $b) {
                $data[] = [$i, $j, self::r($corr[$b][$a] ?? null)];
            }
        }
        return self::base([
            'tooltip' => ['trigger' => 'item', 'formatter' => 'fn:corr'],
            'grid' => ['left' => 90, 'right' => 16, 'top' => 8, 'bottom' => 70],
            'xAxis' => ['type' => 'category', 'data' => $names, 'axisTick' => ['show' => false],
                        'axisLabel' => ['rotate' => 50, 'fontSize' => 10, 'color' => '#374151']],
            'yAxis' => ['type' => 'category', 'data' => $names, 'inverse' => true, 'axisTick' => ['show' => false],
                        'axisLabel' => ['fontSize' => 10, 'color' => '#374151']],
            'visualMap' => ['min' => -1, 'max' => 1, 'calculable' => false, 'orient' => 'horizontal', 'left' => 'center',
                            'bottom' => 0, 'itemHeight' => 120, 'itemWidth' => 10, 'text' => ['هم‌جهت ۱+', '۱- مخالف'],
                            'textStyle' => ['fontSize' => 10], 'inRange' => ['color' => ['#2563eb', '#f3f4f6', '#dc2626']]],
            'series' => [['type' => 'heatmap', 'data' => $data, 'itemStyle' => ['borderColor' => '#fff', 'borderWidth' => 1],
                          'label' => ['show' => count($names) <= 10, 'fontSize' => 9, 'formatter' => 'fn:corrlabel']]],
        ]);
    }

    /** @param list<array{symbol: string, vol: ?float, ret: ?float, weight: float, score: ?float}> $points */
    public static function riskReturn(array $points): array
    {
        $data = [];
        foreach ($points as $p) {
            if ($p['vol'] === null || $p['ret'] === null) {
                continue;
            }
            $s = $p['score'] ?? 50;
            $data[] = ['name' => $p['symbol'], 'value' => [round($p['vol'], 1), round($p['ret'], 1), round($p['weight'], 1)],
                       'itemStyle' => ['color' => $s >= 55 ? self::UP : ($s <= 45 ? self::DOWN : self::MUTED), 'opacity' => 0.75]];
        }
        return self::base([
            'tooltip' => ['trigger' => 'item', 'formatter' => 'fn:riskreturn'],
            'grid' => ['left' => 56, 'right' => 24, 'top' => 24, 'bottom' => 44],
            'xAxis' => self::axis(null, ['type' => 'value', 'name' => 'نوسان سالانه ٪', 'nameLocation' => 'middle',
                                         'nameGap' => 26, 'scale' => true]),
            'yAxis' => self::axis(null, ['type' => 'value', 'name' => 'بازده ۱ سال ٪', 'scale' => true]),
            'series' => [['type' => 'scatter', 'data' => $data, 'symbolSize' => 'fn:bubble',
                          'label' => ['show' => true, 'formatter' => '{b}', 'position' => 'top', 'fontSize' => 10, 'color' => '#374151']]],
        ]);
    }

    // --------------------------------------------------------------- market

    /** @param array<string, float> $series */
    public static function indexLine(array $series, int $showDays = 500): array
    {
        $dates = array_keys($series);
        $values = array_values($series);
        $sma = Indicators::sma($values, 200);
        $start = max(0, 100 - $showDays / max(count($values), 1) * 100);
        return self::base([
            'legend' => ['top' => 0, 'textStyle' => ['fontSize' => 11]],
            'grid' => ['left' => 64, 'right' => 16, 'top' => 32, 'bottom' => 40],
            'xAxis' => self::axis(self::jdates($dates), ['boundaryGap' => false, 'axisLabel' => ['hideOverlap' => true]]),
            'yAxis' => self::axis(null, ['type' => 'value', 'scale' => true, 'axisLabel' => ['formatter' => 'fn:compact']]),
            'series' => [
                ['name' => 'شاخص کل', 'type' => 'line', 'data' => array_map(static fn ($v) => round($v), $values),
                 'showSymbol' => false, 'lineStyle' => ['color' => self::ACCENT, 'width' => 1.6],
                 'itemStyle' => ['color' => self::ACCENT], 'areaStyle' => ['color' => 'rgba(37,99,235,.07)']],
                ['name' => 'میانگین ۲۰۰ روزه', 'type' => 'line', 'data' => array_map(static fn ($v) => self::r($v, 0), $sma),
                 'showSymbol' => false, 'lineStyle' => ['color' => '#8b5cf6', 'width' => 1, 'type' => 'dashed'],
                 'itemStyle' => ['color' => '#8b5cf6']],
            ],
            'dataZoom' => [['type' => 'inside', 'start' => $start, 'end' => 100],
                           ['type' => 'slider', 'bottom' => 4, 'height' => 14, 'start' => $start, 'end' => 100,
                            'borderColor' => 'transparent', 'showDetail' => false]],
        ]);
    }

    /** @param list<array{name: string, value: float, change: float}> $sectors */
    public static function treemap(array $sectors): array
    {
        $data = [];
        foreach ($sectors as $s) {
            if ($s['value'] > 0) {
                $data[] = ['name' => $s['name'], 'value' => [round($s['value']), round($s['change'], 2)],
                           'label' => ['formatter' => 'fn:treemaplabel']];
            }
        }
        return self::base([
            'tooltip' => ['trigger' => 'item', 'formatter' => 'fn:treemap'],
            'visualMap' => ['show' => false, 'type' => 'continuous', 'min' => -3, 'max' => 3, 'dimension' => 1,
                            'inRange' => ['color' => ['#dc2626', '#fecaca', '#f3f4f6', '#bbf7d0', '#059669']]],
            'series' => [['type' => 'treemap', 'data' => $data, 'roam' => false, 'nodeClick' => false,
                          'breadcrumb' => ['show' => false], 'width' => '100%', 'height' => '100%', 'top' => 0, 'left' => 0,
                          'itemStyle' => ['borderColor' => '#fff', 'borderWidth' => 2, 'gapWidth' => 2],
                          'label' => ['show' => true, 'fontSize' => 11, 'color' => '#111827', 'fontFamily' => self::FONT]]],
        ]);
    }

    public static function breadth(int $up, int $down, int $flat): array
    {
        return self::base([
            'tooltip' => ['trigger' => 'item', 'formatter' => '{b}: {c} نماد ({d}٪)'],
            'legend' => ['bottom' => 0, 'textStyle' => ['fontSize' => 11]],
            'series' => [['type' => 'pie', 'radius' => ['50%', '75%'], 'center' => ['50%', '45%'], 'label' => ['show' => false],
                          'itemStyle' => ['borderColor' => '#fff', 'borderWidth' => 2],
                          'data' => [['name' => 'مثبت', 'value' => $up, 'itemStyle' => ['color' => self::UP]],
                                     ['name' => 'بدون تغییر', 'value' => $flat, 'itemStyle' => ['color' => '#d1d5db']],
                                     ['name' => 'منفی', 'value' => $down, 'itemStyle' => ['color' => self::DOWN]]]]],
        ]);
    }

    /** @param list<array{0: string, 1: float}> $items */
    public static function diverging(array $items): array
    {
        usort($items, static fn ($a, $b) => $a[1] <=> $b[1]);
        return self::base([
            'tooltip' => ['trigger' => 'axis', 'axisPointer' => ['type' => 'shadow']],
            'grid' => ['left' => 8, 'right' => 80, 'top' => 8, 'bottom' => 20],
            'xAxis' => self::axis(null, ['type' => 'value', 'axisLabel' => ['formatter' => 'fn:compact']]),
            'yAxis' => self::axis(array_column($items, 0), ['position' => 'right', 'axisTick' => ['show' => false]]),
            'series' => [['type' => 'bar', 'barWidth' => '55%', 'name' => 'اثر',
                          'data' => array_map(static fn ($i) => ['value' => round($i[1], 1),
                              'itemStyle' => ['color' => $i[1] >= 0 ? self::UP : self::DOWN, 'borderRadius' => 3]], $items)]],
        ]);
    }
}
